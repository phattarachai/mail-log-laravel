<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Override;
use Phattarachai\MailLogLaravel\Contracts\MailLogLinkable;
use Phattarachai\MailLogLaravel\Database\Factories\MailLogGroupFactory;
use Phattarachai\MailLogLaravel\Enums\MailLogStatus;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Throwable;

/**
 * @property int $id
 * @property string $fingerprint
 * @property string|null $subject
 * @property string|null $from
 * @property string|null $mailable_class
 * @property string|null $notification_class
 * @property string|null $model_type
 * @property string|null $model_id
 * @property string|null $mailer
 * @property string|null $html_body
 * @property string|null $text_body
 * @property int $sent_count
 * @property int $failed_count
 * @property MailLogStatus|null $latest_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MailLog|null $latestEvent
 */
class MailLogGroup extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use Prunable;

    protected $guarded = [];

    #[Override]
    public function getTable(): string
    {
        return (string) config('mail-log.tables.groups', 'mail_log_groups');
    }

    /**
     * @return HasMany<MailLog, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MailLog::class, 'group_id')->latest();
    }

    /**
     * @return HasOne<MailLog, $this>
     */
    public function latestEvent(): HasOne
    {
        return $this->hasOne(MailLog::class, 'group_id')->ofMany('created_at', 'max');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Apply a finished event to this group: bump the right counter, set
     * latest_status, touch updated_at. Wrapped in a transaction so the
     * status change and counter increment commit together.
     */
    public function recordEvent(MailLog $event): void
    {
        DB::transaction(function () use ($event): void {
            $this->latest_status = $event->status;
            $this->save();

            match ($event->status) {
                MailLogStatus::Sent => $this->increment('sent_count'),
                MailLogStatus::Failed => $this->increment('failed_count'),
                default => null,
            };
        });
    }

    /**
     * Ratio of sent events to (sent + failed). 1.0 when no outcomes recorded.
     */
    public function successRate(): float
    {
        $total = $this->sent_count + $this->failed_count;

        if ($total === 0) {
            return 1.0;
        }

        return $this->sent_count / $total;
    }

    /**
     * Aggregates the dashboard reads through totalSends() / pendingSends(),
     * for withCount() / loadCount().
     *
     * @return array<int|string, mixed>
     */
    public static function sendCounts(): array
    {
        return [
            'events',
            'events as pending_count' => fn (Builder $events) => $events->where('status', MailLogStatus::Pending),
        ];
    }

    /**
     * Every send in the group: sent + failed + still pending. Uses the
     * events_count / pending_count aggregates when the query loaded them.
     */
    public function totalSends(): int
    {
        $loaded = $this->getAttributes()['events_count'] ?? null;

        if ($loaded !== null) {
            return (int) $loaded;
        }

        return $this->sent_count + $this->failed_count + $this->pendingSends();
    }

    public function pendingSends(): int
    {
        $loaded = $this->getAttributes()['pending_count'] ?? null;

        if ($loaded !== null) {
            return (int) $loaded;
        }

        return $this->events()->where('status', MailLogStatus::Pending)->count();
    }

    /**
     * Distinct recipients across every send in the group, in the order they
     * were first mailed (oldest send first). Within a send, `to` comes before
     * `cc` and `bcc`, so the primary recipient of the first send leads.
     *
     * @return Collection<int, string>
     */
    public function uniqueRecipients(): Collection
    {
        return MailLog::query()
            ->where('group_id', $this->id)
            ->oldest('created_at')
            ->oldest('id')
            ->get(['to', 'cc', 'bcc'])
            ->flatMap(fn (MailLog $event): array => $event->allRecipients())
            ->unique()
            ->values();
    }

    /**
     * The originating host model when it implements MailLogLinkable. Never
     * throws: a morph type whose class is gone, or a blocked lazy load,
     * falls back to null and the dashboard renders type#id as text.
     */
    public function linkable(): ?MailLogLinkable
    {
        if ($this->model_type === null || $this->model_id === null) {
            return null;
        }

        try {
            $model = $this->getRelationValue('model');
        } catch (Throwable) {
            return null;
        }

        return $model instanceof MailLogLinkable ? $model : null;
    }

    /**
     * Prunable scope — groups idle past the configured retention window.
     * Events cascade-delete via FK when a group is pruned.
     */
    public function prunable(): Builder
    {
        $days = config('mail-log.retention_days');

        if ($days === null) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where('updated_at', '<', now()->subDays((int) $days));
    }

    public function registerMediaCollections(): void
    {
        $collection = $this->addMediaCollection(
            (string) config('mail-log.attachments.collection', 'attachments')
        );

        $disk = config('mail-log.attachments.disk');

        if ($disk !== null) {
            $collection->useDisk((string) $disk);
        }
    }

    /**
     * Register a stable morph alias so model() doesn't bake the
     * package's fully-qualified class name into host data.
     */
    public static function registerMorphMap(): void
    {
        Relation::morphMap([
            (string) config('mail-log.morph_alias', 'mail_log_group') => static::class,
        ]);
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'latest_status' => MailLogStatus::class,
            'sent_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    protected static function newFactory(): Factory
    {
        return MailLogGroupFactory::new();
    }
}
