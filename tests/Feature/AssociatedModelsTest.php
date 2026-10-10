<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\Mail;
use Phattarachai\MailLogLaravel\Concerns\HasMailLog;
use Phattarachai\MailLogLaravel\Contracts\MailLogLinkable;
use Phattarachai\MailLogLaravel\MailLog as MailLogDashboard;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLogAssociation;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;

beforeEach(function (): void {
    Relation::morphMap([], merge: false);
    MailLogGroup::registerMorphMap();

    config()->set('mail.default', 'array');
    config()->set('mail.mailers.array', ['transport' => 'array']);
    config()->set('mail.from', ['address' => 'app@example.com', 'name' => 'App']);

    MailLogDashboard::auth(fn () => true);
});

function associationCustomer(string $name): AssociationCustomer
{
    return AssociationCustomer::query()->create(MailLogGroup::factory()->raw(['subject' => $name]));
}

it('stores the primary model and every extra model per send, without changing the grouping', function (): void {
    $order = MailLogGroup::factory()->create();
    $emma = associationCustomer('Emma Carter');
    $somchai = associationCustomer('Somchai W.');

    Mail::to('a@example.com')->send(new AssociatedOrderMail($order, [$emma]));
    Mail::to('b@example.com')->send(new AssociatedOrderMail($order, [$somchai, $somchai]));

    $group = MailLogGroup::query()->where('mailable_class', AssociatedOrderMail::class)->sole();
    [$first, $second] = MailLog::query()->with('associations')->oldest('id')->get()->all();

    $pairs = fn (MailLog $event): array => $event->associations
        ->map(fn (MailLogAssociation $a): array => [$a->model_type, $a->model_id, $a->is_primary])
        ->sortBy(fn (array $pair): string => (int) ! $pair[2].$pair[0].$pair[1])
        ->values()
        ->all();

    expect($group->sent_count)->toBe(2)
        ->and($pairs($first))->toBe([['mail_log_group', (string) $order->id, true], [AssociationCustomer::class, (string) $emma->id, false]])
        ->and($pairs($second))->toBe([['mail_log_group', (string) $order->id, true], [AssociationCustomer::class, (string) $somchai->id, false]]);
});

it('filters the index by a model, matching the primary model or any associated one', function (): void {
    $order = MailLogGroup::factory()->create(['subject' => 'Owner order']);
    $emma = associationCustomer('Emma Carter');

    Mail::to('a@example.com')->send(new AssociatedOrderMail($order, [$emma], subjectLine: 'Shipped to Emma'));
    Mail::to('b@example.com')->send(new AssociatedOrderMail(MailLogGroup::factory()->create(), [], subjectLine: 'Unrelated order'));

    $this->get(route('mail-log.index', ['model' => AssociationCustomer::class.':'.$emma->id]))
        ->assertOk()->assertSee('Shipped to Emma')->assertDontSee('Unrelated order');

    $this->get(route('mail-log.index', ['model' => 'mail_log_group:'.$order->id]))
        ->assertOk()->assertSee('Shipped to Emma')->assertDontSee('Unrelated order');

    $this->get(route('mail-log.index', ['model' => 'no-separator']))
        ->assertOk()->assertDontSee('Shipped to Emma');
});

it('lists related models on the group page, linked through MailLogLinkable', function (): void {
    $emma = associationCustomer('Emma Carter');

    Mail::to('a@example.com')->send(new AssociatedOrderMail(MailLogGroup::factory()->create(), [$emma]));

    $group = MailLogGroup::query()->where('mailable_class', AssociatedOrderMail::class)->sole();

    $this->get(route('mail-log.show', $group))
        ->assertOk()
        ->assertSee('Related models')
        ->assertSee('href="https://crm.test/customers/'.$emma->id.'"', escape: false)
        ->assertSee(route('mail-log.index', ['model' => AssociationCustomer::class.':'.$emma->id]), escape: false);
});

it('shows a send its own extra models, but not ones every send in the group shares', function (): void {
    $order = MailLogGroup::factory()->create();
    $emma = associationCustomer('Emma Carter');
    $somchai = associationCustomer('Somchai W.');

    Mail::to('a@example.com')->send(new AssociatedOrderMail($order, [$emma, $somchai]));
    Mail::to('b@example.com')->send(new AssociatedOrderMail($order, [$emma]));

    $group = MailLogGroup::query()->where('mailable_class', AssociatedOrderMail::class)->sole();
    $html = $this->get(route('mail-log.show', $group))->assertOk()->getContent();
    $start = strpos($html, 'Sends (');
    $sendsTable = substr($html, $start, strpos($html, '</table>', $start) - $start);

    expect(substr_count($sendsTable, 'Also:'))->toBe(1)
        ->and($sendsTable)->toContain('Somchai W.')
        ->and(substr_count($sendsTable, 'Emma Carter'))->toBe(0);
});

it('labels the model filter with the MailLogLinkable title', function (): void {
    $emma = associationCustomer('คุณณัฐญา ส.');

    $this->get(route('mail-log.index', ['model' => AssociationCustomer::class.':'.$emma->id]))
        ->assertOk()
        ->assertSee('คุณณัฐญา ส.');

    $this->get(route('mail-log.index', ['model' => 'App\\Models\\Gone:9']))
        ->assertOk()
        ->assertSee('Gone#9');
});

class AssociationCustomer extends Model implements MailLogLinkable
{
    protected $table = 'mail_log_groups';

    protected $guarded = [];

    public function mailLogTitle(): string
    {
        return (string) $this->subject;
    }

    public function mailLogUrl(): ?string
    {
        return 'https://crm.test/customers/'.$this->id;
    }
}

class AssociatedOrderMail extends Mailable
{
    use HasMailLog;

    /**
     * @param  array<int, Model>  $extras
     */
    public function __construct(public Model $order, public array $extras, public string $subjectLine = 'Order shipped') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Order shipped</p>');
    }

    public function headers(): Headers
    {
        return $this->withMailLog(new Headers);
    }

    protected function mailLogModel(): ?Model
    {
        return $this->order;
    }

    protected function mailLogModels(): iterable
    {
        return $this->extras;
    }
}
