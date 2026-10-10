<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Storage;
use Phattarachai\MailLogLaravel\Contracts\MailLogLinkable;
use Phattarachai\MailLogLaravel\Enums\MailLogStatus;
use Phattarachai\MailLogLaravel\Mail\TestMail;
use Phattarachai\MailLogLaravel\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLog as MailLogEvent;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;

beforeEach(function (): void {
    Relation::morphMap([], merge: false);
    MailLogGroup::registerMorphMap();

    config()->set('mail.default', 'array');
    config()->set('mail.mailers.array', ['transport' => 'array']);
    config()->set('mail.from', ['address' => 'app@example.com', 'name' => 'App']);

    MailLog::auth(fn () => true);
});

it('lists groups on the index with sent-count and recipient summary', function (): void {
    $group = MailLogGroup::factory()->create([
        'subject' => 'Order #1042 shipped',
        'sent_count' => 3,
        'failed_count' => 0,
        'latest_status' => MailLogStatus::Sent,
        'mailable_class' => 'App\\Mail\\OrderShipped',
        'mailer' => 'smtp',
    ]);

    MailLogEvent::factory()->for($group, 'group')->create(['to' => ['a@example.com']]);
    MailLogEvent::factory()->for($group, 'group')->create(['to' => ['b@example.com']]);
    MailLogEvent::factory()->for($group, 'group')->create(['to' => ['c@example.com']]);

    $response = $this->get(route('mail-log.index'));

    $response->assertOk()
        ->assertSee('Order #1042 shipped')
        ->assertSee('OrderShipped')
        ->assertSee('smtp')
        ->assertSee('+2 more');
});

it('filters the index by status', function (): void {
    MailLogGroup::factory()->create([
        'subject' => 'Sent A',
        'latest_status' => MailLogStatus::Sent,
        'sent_count' => 1,
    ]);
    MailLogGroup::factory()->create([
        'subject' => 'Failed B',
        'latest_status' => MailLogStatus::Failed,
        'failed_count' => 1,
    ]);

    $response = $this->get(route('mail-log.index', ['status' => 'failed']));

    $response->assertOk()
        ->assertSee('Failed B')
        ->assertDontSee('Sent A');
});

it('filters the index by has_failures', function (): void {
    MailLogGroup::factory()->create([
        'subject' => 'Clean run',
        'sent_count' => 5,
        'failed_count' => 0,
    ]);
    MailLogGroup::factory()->create([
        'subject' => 'Mixed run',
        'sent_count' => 4,
        'failed_count' => 1,
        'latest_status' => MailLogStatus::Sent,
    ]);

    $response = $this->get(route('mail-log.index', ['has_failures' => 1]));

    $response->assertOk()
        ->assertSee('Mixed run')
        ->assertDontSee('Clean run');
});

it('filters the index by search across subject, mailable class, and recipients', function (): void {
    $bySubject = MailLogGroup::factory()->create(['subject' => 'Daily digest', 'mailable_class' => 'App\\Mail\\Digest']);
    $byClass = MailLogGroup::factory()->create(['subject' => 'Welcome aboard', 'mailable_class' => 'App\\Mail\\OrderShipped']);
    $byRecipient = MailLogGroup::factory()->create(['subject' => 'Password reset', 'mailable_class' => 'App\\Mail\\PasswordReset']);
    MailLogEvent::factory()->for($byRecipient, 'group')->create(['to' => ['order-specific@example.com']]);

    expect($this->get(route('mail-log.index', ['search' => 'Digest']))->getContent())
        ->toContain('Daily digest')
        ->not->toContain('Welcome aboard');

    expect($this->get(route('mail-log.index', ['search' => 'OrderShipped']))->getContent())
        ->toContain('Welcome aboard')
        ->not->toContain('Daily digest');

    expect($this->get(route('mail-log.index', ['search' => 'order-specific']))->getContent())
        ->toContain('Password reset')
        ->not->toContain('Daily digest');
});

it('shows the body preview iframe and sends table on the group detail page', function (): void {
    $group = MailLogGroup::factory()->create([
        'subject' => 'Order #1042 shipped',
        'sent_count' => 2,
        'html_body' => '<p>Order shipped</p>',
        'mailable_class' => 'App\\Mail\\OrderShipped',
        'mailer' => 'smtp',
    ]);
    MailLogEvent::factory()->for($group, 'group')->create(['to' => ['a@example.com']]);
    MailLogEvent::factory()->for($group, 'group')->create(['to' => ['b@example.com']]);

    $response = $this->get(route('mail-log.show', $group));

    $response->assertOk()
        ->assertSee('Order #1042 shipped')
        ->assertSee('Sends (2)')
        ->assertSee('a@example.com')
        ->assertSee('b@example.com')
        ->assertSee('App\\Mail\\OrderShipped', escape: false)
        ->assertSeeText('bodyPreview');
});

it('returns event JSON via /events/{event}', function (): void {
    $group = MailLogGroup::factory()->create();
    $event = MailLogEvent::factory()->for($group, 'group')->create([
        'to' => ['target@example.com'],
        'status' => MailLogStatus::Sent,
        'seconds' => 0.123,
    ]);

    $response = $this->get(route('mail-log.event', ['group' => $group, 'event' => $event]));

    $response->assertOk()
        ->assertJsonPath('id', $event->id)
        ->assertJsonPath('group_id', $group->id)
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('to.0', 'target@example.com')
        ->assertJsonPath('seconds', 0.123);
});

it('404s when the event does not belong to the group', function (): void {
    $group = MailLogGroup::factory()->create();
    $other = MailLogGroup::factory()->create();
    $event = MailLogEvent::factory()->for($other, 'group')->create();

    $this->get(route('mail-log.event', ['group' => $group, 'event' => $event]))->assertNotFound();
});

it('downloads an attachment via /attachments/{media}', function (): void {
    config()->set('mail-log.attachments.disk', 'public');
    Storage::fake('public');

    $group = MailLogGroup::factory()->create();
    $tempPath = tempnam(sys_get_temp_dir(), 'mail_log_test_');
    file_put_contents($tempPath, 'invoice contents');

    $media = $group->addMedia($tempPath)
        ->usingFileName('invoice.pdf')
        ->toMediaCollection('attachments');

    $response = $this->get(route('mail-log.attachment', ['group' => $group, 'media' => $media->id]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('invoice.pdf');
});

it('test-send dispatches a mail and creates a new TestMail group', function (): void {
    $response = $this
        ->from(route('mail-log.index'))
        ->post(route('mail-log.test-send'), [
            'email' => 'tester@example.com',
            'message' => 'Hello from the test',
        ]);

    $response->assertRedirect(route('mail-log.index'));

    $group = MailLogGroup::query()
        ->where('mailable_class', TestMail::class)
        ->firstOrFail();

    expect($group->sent_count)->toBe(1);
    expect(MailLogEvent::query()->where('group_id', $group->id)->count())->toBe(1);
});

it('test-send route 404s when test-send is disabled', function (): void {
    config()->set('mail-log.test_send.enabled', value: false);

    $this->post(route('mail-log.test-send'), ['email' => 'x@example.com'])->assertNotFound();
});

it('deleting a group cascade-removes its events', function (): void {
    $group = MailLogGroup::factory()->create();
    MailLogEvent::factory()->for($group, 'group')->count(3)->create();

    $this
        ->from(route('mail-log.show', $group))
        ->delete(route('mail-log.destroy', $group))
        ->assertRedirect(route('mail-log.index'));

    expect(MailLogGroup::query()->find($group->id))->toBeNull()
        ->and(MailLogEvent::query()->where('group_id', $group->id)->count())->toBe(0);
});

it('blocks all UI routes when MailLog::auth returns false', function (): void {
    MailLog::auth(fn () => false);

    $group = MailLogGroup::factory()->create();

    $this->get(route('mail-log.index'))->assertForbidden();
    $this->get(route('mail-log.show', $group))->assertForbidden();
    $this->delete(route('mail-log.destroy', $group))->assertForbidden();
    $this->post(route('mail-log.test-send'), ['email' => 'x@example.com'])->assertForbidden();
});

it('renders the header back-link when back_link config has a url but no label key', function (): void {
    // Installs published before 0.2.0 carry a back_link array with only `url`
    // (the `label` key was added later). The header must not blow up with an
    // "Undefined array key label" fatal — it should fall back to "Back".
    config()->set('mail-log.ui.back_link', ['url' => 'https://app.example.com']);

    MailLogGroup::factory()->create();

    $this->get(route('mail-log.index'))
        ->assertOk()
        ->assertSee('https://app.example.com')
        ->assertSee('Back');
});

it('inlines the dist CSS + JS bundles via the asset helpers', function (): void {
    $cssHtml = (string) MailLog::css();
    $jsHtml = (string) MailLog::js();

    expect($cssHtml)->toStartWith('<style>')
        ->and($cssHtml)->toEndWith('</style>')
        ->and(strlen($cssHtml))->toBeGreaterThan(200);

    expect($jsHtml)->toContain('<script type="module">')
        ->and($jsHtml)->toContain('window.MailLog')
        ->and(strlen($jsHtml))->toBeGreaterThan(500);
});

it('searches recipients case-insensitively across to, cc and bcc', function (): void {
    $byTo = MailLogGroup::factory()->create(['subject' => 'Invoice ready']);
    MailLogEvent::factory()->for($byTo, 'group')->create(['to' => ['Customer@Example.com']]);
    $byCc = MailLogGroup::factory()->create(['subject' => 'Order packed']);
    MailLogEvent::factory()->for($byCc, 'group')->create(['to' => ['buyer@example.com'], 'cc' => ['warehouse@example.com']]);

    expect($this->get(route('mail-log.index', ['search' => 'CUSTOMER@example']))->getContent())
        ->toContain('Invoice ready')
        ->not->toContain('Order packed');

    expect($this->get(route('mail-log.index', ['search' => 'Warehouse']))->getContent())
        ->toContain('Order packed')
        ->not->toContain('Invoice ready');

    expect($this->get(route('mail-log.index', ['search' => 'INVOICE']))->getContent())
        ->toContain('Invoice ready');
});

it('renders a MailLogLinkable model as a deep link on the index and show pages', function (): void {
    $order = LinkableOrder::query()->create(MailLogGroup::factory()->raw());
    $group = MailLogGroup::factory()->create([
        'subject' => 'Order shipped',
        'model_type' => LinkableOrder::class,
        'model_id' => (string) $order->id,
    ]);

    $link = 'href="https://shop.test/orders/'.$order->id.'"';

    $this->get(route('mail-log.index'))->assertOk()->assertSee($link, escape: false)->assertSee('Order #'.$order->id);
    $this->get(route('mail-log.show', $group))->assertOk()->assertSee($link, escape: false);
});

it('falls back to type#id when the linked model class no longer exists', function (): void {
    $group = MailLogGroup::factory()->create([
        'subject' => 'Legacy mail',
        'model_type' => 'App\\Models\\RemovedModel',
        'model_id' => '9',
    ]);

    $this->get(route('mail-log.index'))->assertOk()->assertSee('RemovedModel#9');
    $this->get(route('mail-log.show', $group))->assertOk()->assertSee('App\\Models\\RemovedModel#9');
});

it('counts pending sends in the index, the totals and the group stats', function (): void {
    $group = MailLogGroup::factory()->create([
        'subject' => 'Still sending',
        'sent_count' => 0,
        'failed_count' => 0,
        'latest_status' => MailLogStatus::Pending,
    ]);
    MailLogEvent::factory()->for($group, 'group')->pending()->create();

    expect($group->fresh()->totalSends())->toBe(1);

    $this->get(route('mail-log.index'))->assertOk()->assertSeeInOrder(['<span>1</span> sends total'], escape: false);
    $this->get(route('mail-log.show', $group))->assertOk()->assertSee('1 pending');
});

it('shows the first-sent date when the group started on an earlier day', function (): void {
    $group = MailLogGroup::factory()->create([
        'created_at' => now()->setDate(2026, 5, 9)->setTime(10, 14),
        'updated_at' => now()->setDate(2026, 5, 19)->setTime(15, 7),
        'sent_count' => 2,
    ]);
    MailLogEvent::factory()->for($group, 'group')->count(2)->create();

    $this->get(route('mail-log.index'))->assertOk()->assertSee('first 9 พ.ค. 69 · 10:14');
});

it('shows when a send was confirmed in the sends table, not when it started', function (): void {
    $group = MailLogGroup::factory()->create();
    MailLogEvent::factory()->for($group, 'group')->create([
        'created_at' => now()->setDate(2026, 5, 19)->setTime(15, 0),
        'sent_at' => now()->setDate(2026, 5, 19)->setTime(15, 7),
    ]);

    $this->get(route('mail-log.show', $group))->assertOk()->assertSee('19 พ.ค. 69 · 15:07')->assertDontSee('19 พ.ค. 69 · 15:00');
});

it('renders the chrome without third-party fonts or a theme toggle', function (): void {
    MailLogGroup::factory()->create();

    $this->get(route('mail-log.index'))
        ->assertOk()
        ->assertDontSee('fonts.bunny.net')
        ->assertDontSee('toggleTheme');
});

it('flashes an English confirmation after deleting a group', function (): void {
    $group = MailLogGroup::factory()->create();

    $this->delete(route('mail-log.destroy', $group))
        ->assertSessionHas('mail-log:flash', 'Deleted the group and every send in it.');
});

class LinkableOrder extends Model implements MailLogLinkable
{
    protected $table = 'mail_log_groups';

    protected $guarded = [];

    public function mailLogTitle(): string
    {
        return 'Order #'.$this->id;
    }

    public function mailLogUrl(): ?string
    {
        return 'https://shop.test/orders/'.$this->id;
    }
}
