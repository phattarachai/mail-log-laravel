<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Phattarachai\MailLogLaravel\Enums\MailLogStatus;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;
use Throwable;

class GroupController
{
    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => (string) $request->query('status', ''),
            'has_failures' => $request->boolean('has_failures'),
        ];

        $query = MailLogGroup::query()->latest('updated_at');

        if ($filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $eventsTable = (new MailLog)->getTable();
            $groupsTable = (new MailLogGroup)->getTable();

            $query->where(function (Builder $q) use ($term, $eventsTable, $groupsTable): void {
                $q->whereLike('subject', $term)
                    ->orWhereLike('mailable_class', $term)
                    ->orWhereLike('notification_class', $term)
                    ->orWhereExists(function (QueryBuilder $sub) use ($term, $eventsTable, $groupsTable): void {
                        $sub->select('id')
                            ->from($eventsTable)
                            ->whereColumn('group_id', $groupsTable.'.id')
                            ->where(fn (QueryBuilder $recipients) => $this->whereRecipientsLike($recipients, $term));
                    });
            });
        }

        $statusEnum = MailLogStatus::tryFrom($filters['status']);
        if ($statusEnum !== null) {
            $query->where('latest_status', $statusEnum->value);
        }

        if ($filters['has_failures']) {
            $query->where('failed_count', '>', 0);
        }

        $groups = $query
            ->with(['latestEvent'])
            ->withCount(MailLogGroup::sendCounts())
            ->paginate((int) config('mail-log.ui.page_size', 25))
            ->withQueryString();

        $this->loadLinkedModels($groups->getCollection());

        $stats = [
            'groups' => MailLogGroup::query()->count(),
            'sends' => MailLog::query()->count(),
        ];

        return view('mail-log::index', [
            'groups' => $groups,
            'filters' => $filters,
            'stats' => $stats,
        ]);
    }

    public function show(Request $request, MailLogGroup $group): View
    {
        $group->load('media')->loadCount(MailLogGroup::sendCounts());
        $this->loadLinkedModels(new EloquentCollection([$group]));

        $events = $group->events()
            ->paginate((int) config('mail-log.ui.page_size', 25))
            ->withQueryString();

        return view('mail-log::show', [
            'group' => $group,
            'events' => $events,
        ]);
    }

    public function destroy(Request $request, MailLogGroup $group): RedirectResponse
    {
        $group->delete();

        return to_route('mail-log.index')
            ->with('mail-log:flash', 'Deleted the group and every send in it.');
    }

    /**
     * Recipients live in json columns (to / cc / bcc). Postgres compiles a
     * LIKE on them to `"to"::text ilike ?`; MySQL compares JSON as a binary
     * string, so lower-case both sides there to keep search case-insensitive
     * (MariaDbGrammar extends MySqlGrammar).
     */
    private function whereRecipientsLike(QueryBuilder $query, string $term): void
    {
        $grammar = $query->getGrammar();
        $isMySql = $grammar instanceof MySqlGrammar;

        foreach (['to', 'cc', 'bcc'] as $column) {
            if ($isMySql) {
                $query->orWhereRaw('lower(cast('.$grammar->wrap($column).' as char)) like ?', [mb_strtolower($term)]);

                continue;
            }

            $query->orWhereLike($column, $term);
        }
    }

    /**
     * Eager-load the host models for MailLogLinkable deep links. A morph type
     * whose class no longer exists must not take the dashboard down.
     *
     * @param  EloquentCollection<int, MailLogGroup>  $groups
     */
    private function loadLinkedModels(EloquentCollection $groups): void
    {
        try {
            $groups->load('model');
        } catch (Throwable) {
            // MailLogGroup::linkable() resolves per row and degrades to text.
        }
    }
}
