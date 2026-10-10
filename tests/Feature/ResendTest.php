<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Phattarachai\MailLogLaravel\Concerns\HasMailLog;
use Phattarachai\MailLogLaravel\MailLog as MailLogDashboard;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    Relation::morphMap([], merge: false);
    MailLogGroup::registerMorphMap();

    config()->set('mail.default', 'array');
    config()->set('mail.mailers.array', ['transport' => 'array']);
    config()->set('mail.from', ['address' => 'app@example.com', 'name' => 'App']);

    MailLogDashboard::auth(fn () => true);
});

function sentEmails(): array
{
    return app('mailer')->getSymfonyTransport()->messages()
        ->map(fn ($sent): Email => $sent->getOriginalMessage())
        ->all();
}

function resendableSend(array $mailOptions = []): MailLog
{
    $order = MailLogGroup::factory()->create(['subject' => 'Order #1042']);

    Mail::to([['email' => 'customer@example.com', 'name' => 'Customer']])->cc('warehouse@example.com')
        ->send(new ResendableOrderMail($order, ...$mailOptions));

    return MailLog::query()->latest('id')->firstOrFail();
}

it('stores nothing to resend for a Mailable that does not opt in', function (): void {
    $event = resendableSend(['resendable' => false]);

    expect($event->resend_payload)->toBeNull();

    $this->get(route('mail-log.show', $event->group_id))->assertOk()->assertDontSee('↻ Resend');
});

it('stores the opted-in Mailable encrypted, never in plain text', function (): void {
    $event = resendableSend();

    expect($event->resend_payload)->not->toBeNull()
        ->and($event->resend_payload)->not->toContain('ResendableOrderMail')
        ->and($event->toArray())->not->toHaveKey('resend_payload');

    $this->get(route('mail-log.show', $event->group_id))->assertOk()->assertSee('↻ Resend');
});

it('resends to another address with fresh content, as a new send in the same group', function (): void {
    $event = resendableSend();
    MailLogGroup::query()->whereKey(MailLogGroup::query()->where('subject', 'Order #1042')->value('id'))
        ->update(['subject' => 'Order #1042 (updated)']);

    $this->from(route('mail-log.show', $event->group_id))
        ->post(route('mail-log.resend', [$event->group_id, $event->id]), ['recipients' => 'support@example.com'])
        ->assertRedirect(route('mail-log.show', $event->group_id))
        ->assertSessionHas('mail-log:flash');

    $resent = MailLog::query()->latest('id')->firstOrFail();
    $email = collect(sentEmails())->last();

    expect($resent->id)->not->toBe($event->id)
        ->and($resent->group_id)->toBe($event->group_id)
        ->and($resent->resent_from_id)->toBe($event->id)
        ->and($resent->to)->toBe(['support@example.com'])
        ->and($resent->cc)->toBeNull()
        ->and($email->getHtmlBody())->toContain('Order #1042 (updated)')
        ->and($resent->group->sent_count)->toBe(2);
});

it('resends to the original to and cc when no recipients are given', function (): void {
    $event = resendableSend();

    $this->post(route('mail-log.resend', [$event->group_id, $event->id]), ['recipients' => ''])
        ->assertSessionHas('mail-log:flash');

    $resent = MailLog::query()->latest('id')->firstOrFail();

    expect($resent->to)->toBe(['Customer <customer@example.com>'])
        ->and($resent->cc)->toBe(['warehouse@example.com']);
});

it('refuses to resend elsewhere a Mailable that adds its own recipients', function (): void {
    $event = resendableSend(['envelopeRecipient' => 'fixed@example.com']);
    $sendsBefore = MailLog::query()->count();

    $this->post(route('mail-log.resend', [$event->group_id, $event->id]), ['recipients' => 'someone-else@example.com'])
        ->assertSessionHas('mail-log:error', fn (string $message): bool => str_contains($message, 'adds recipients of its own'));

    expect(MailLog::query()->count())->toBe($sendsBefore)
        ->and(collect(sentEmails())->flatMap(fn (Email $email) => $email->getTo())->map->getAddress()->all())
        ->not->toContain('someone-else@example.com');
});

it('explains when the stored Mailable can no longer be restored', function (): void {
    $event = resendableSend();
    MailLogGroup::query()->where('subject', 'Order #1042')->delete();

    $this->post(route('mail-log.resend', [$event->group_id, $event->id]), ['recipients' => 'support@example.com'])
        ->assertSessionHas('mail-log:error', fn (string $message): bool => str_contains($message, "can't be restored"));
});

it('validates the recipients', function (): void {
    $event = resendableSend();

    $this->post(route('mail-log.resend', [$event->group_id, $event->id]), ['recipients' => 'not-an-email'])
        ->assertSessionHasErrors('recipients.0');
});

it('guards the resend route like the rest of the dashboard', function (): void {
    $event = resendableSend();
    $other = MailLogGroup::factory()->create();

    $this->post(route('mail-log.resend', [$other->id, $event->id]))->assertNotFound();

    config()->set('mail-log.resend.enabled', value: false);
    $this->post(route('mail-log.resend', [$event->group_id, $event->id]))->assertNotFound();

    config()->set('mail-log.resend.enabled', value: true);
    MailLogDashboard::auth(fn () => false);
    $this->post(route('mail-log.resend', [$event->group_id, $event->id]))->assertForbidden();
});

it('resends from the command line', function (): void {
    $event = resendableSend();

    $this->artisan('mail-log:resend', ['event' => $event->id, '--to' => ['ops@example.com']])
        ->expectsOutputToContain('Resent send #'.$event->id.' to ops@example.com.')
        ->assertSuccessful();

    expect(MailLog::query()->latest('id')->first()->to)->toBe(['ops@example.com']);

    $plain = resendableSend(['resendable' => false]);

    $this->artisan('mail-log:resend', ['event' => $plain->id])->assertFailed();
    $this->artisan('mail-log:resend', ['event' => 999999])->assertFailed();
});

class ResendableOrderMail extends Mailable
{
    use HasMailLog;
    use SerializesModels;

    public function __construct(public MailLogGroup $order, public bool $resendable = true, public ?string $envelopeRecipient = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(to: $this->envelopeRecipient === null ? [] : [$this->envelopeRecipient], subject: 'Your order');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->order->subject).'</p>');
    }

    public function headers(): Headers
    {
        return $this->withMailLog(new Headers);
    }

    protected function mailLogModel(): ?Model
    {
        return $this->order;
    }

    protected function mailLogResendable(): bool
    {
        return $this->resendable;
    }
}
