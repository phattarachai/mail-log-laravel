<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Phattarachai\MailLogLaravel\Concerns\HasMailLog;
use Phattarachai\MailLogLaravel\Enums\MailLogStatus;
use Phattarachai\MailLogLaravel\Listeners\LogOutgoingMail;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

beforeEach(function (): void {
    Relation::morphMap([], merge: false);
    MailLogGroup::registerMorphMap();

    Mail::extend('failing', fn () => new FailingTransport);

    config()->set('mail.default', 'failing');
    config()->set('mail.mailers.failing', ['transport' => 'failing']);
    config()->set('mail.from', ['address' => 'app@example.com', 'name' => 'App']);
    config()->set('queue.default', 'sync');
});

/**
 * A send that throws in the transport, the way an SMTP outage does.
 */
function sendThatThrows(Mailable $mailable): void
{
    try {
        Mail::to('a@example.com')->send($mailable);
    } catch (TransportException) {
        // The host would see this; the log row is what is under test.
    }
}

function queueJobMock(string $uuid, int $attempts, bool $hasFailed): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('attempts')->andReturn($attempts);
    $job->shouldReceive('hasFailed')->andReturn($hasFailed);
    $job->shouldReceive('payload')->andReturn(['data' => ['commandName' => 'App\\Jobs\\SomethingElse']]);

    return $job;
}

it('settles a queued notification whose mail fails in the transport', function (): void {
    expect(fn () => Notification::route('mail', 'a@example.com')->notify(new QueuedWelcomeNotification))
        ->toThrow(TransportException::class);

    $row = MailLog::query()->sole();

    expect($row->status)->toBe(MailLogStatus::Failed)
        ->and($row->error_message)->toContain('Connection refused')
        ->and($row->group->failed_count)->toBe(1)
        ->and($row->group->latest_status)->toBe(MailLogStatus::Failed);
});

it('settles a queued mailable on the sync queue without also flipping an older pending row', function (): void {
    $order = MailLogGroup::factory()->create();

    config()->set('mail.mailers.array', ['transport' => 'array']);
    Mail::mailer('array')->to('a@example.com')->send(new SettlementOrderMail($order));
    $olderRow = MailLog::query()->sole();
    $olderRow->update(['status' => MailLogStatus::Pending, 'created_at' => now()->subHour()]);

    expect(fn () => Mail::to('b@example.com')->queue(new SettlementOrderMail($order)))
        ->toThrow(TransportException::class);

    $failedRow = MailLog::query()->whereKeyNot($olderRow->id)->sole();

    expect($failedRow->status)->toBe(MailLogStatus::Failed)
        ->and($failedRow->to)->toBe(['b@example.com'])
        ->and($olderRow->refresh()->status)->toBe(MailLogStatus::Pending);
});

it('records one failed row per failed attempt of a retried job and leaves nothing pending', function (): void {
    $order = MailLogGroup::factory()->create();

    sendThatThrows(new SettlementOrderMail($order));
    event(new JobExceptionOccurred('redis', queueJobMock('job-1', attempts: 1, hasFailed: false), new RuntimeException('Connection timed out')));

    sendThatThrows(new SettlementOrderMail($order));
    $finalAttempt = queueJobMock('job-1', attempts: 2, hasFailed: true);
    event(new JobFailed('redis', $finalAttempt, new RuntimeException('Connection timed out')));
    event(new JobExceptionOccurred('redis', $finalAttempt, new RuntimeException('Connection timed out')));

    $rows = MailLog::query()->oldest('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->all())->toBe([MailLogStatus::Failed, MailLogStatus::Failed])
        ->and($rows[0]->error_message)->toBe('Attempt 1 failed, the job will retry: Connection timed out')
        ->and($rows[1]->error_message)->toBe('Connection timed out')
        ->and($rows[0]->group->failed_count)->toBe(2);
});

it('settles a synchronous send that threw when the request terminates', function (): void {
    sendThatThrows(new SettlementOrderMail(MailLogGroup::factory()->create()));

    expect(MailLog::query()->sole()->status)->toBe(MailLogStatus::Pending);

    $this->app->terminate();

    $row = MailLog::query()->sole();

    expect($row->status)->toBe(MailLogStatus::Failed)
        ->and($row->error_message)->toBe(LogOutgoingMail::UNCONFIRMED_MESSAGE);
});

it('settles a send the job caught itself when the job finishes', function (): void {
    sendThatThrows(new SettlementOrderMail(MailLogGroup::factory()->create()));

    event(new JobProcessed('redis', queueJobMock('job-2', attempts: 1, hasFailed: false)));

    expect(MailLog::query()->sole()->status)->toBe(MailLogStatus::Failed);
});

it('leaves confirmed sends alone when the request terminates', function (): void {
    config()->set('mail.default', 'array');
    config()->set('mail.mailers.array', ['transport' => 'array']);

    Mail::to('a@example.com')->send(new SettlementOrderMail(MailLogGroup::factory()->create()));

    $this->app->terminate();

    $row = MailLog::query()->sole();

    expect($row->status)->toBe(MailLogStatus::Sent)
        ->and($row->group->failed_count)->toBe(0);
});

it('settles pending sends older than the timeout with mail-log:settle-stale', function (): void {
    config()->set('mail-log.pending_timeout_minutes', 15);

    $group = MailLogGroup::factory()->create(['sent_count' => 0, 'failed_count' => 0]);
    $stale = MailLog::factory()->for($group, 'group')->pending()->create(['created_at' => now()->subMinutes(20)]);
    $recent = MailLog::factory()->for($group, 'group')->pending()->create(['created_at' => now()->subMinutes(5)]);
    $sent = MailLog::factory()->for($group, 'group')->create(['created_at' => now()->subHour()]);

    $this->artisan('mail-log:settle-stale')
        ->expectsOutputToContain('Settled 1 stale pending send.')
        ->assertSuccessful();

    expect($stale->refresh()->status)->toBe(MailLogStatus::Failed)
        ->and($stale->error_message)->toContain('No delivery confirmation within 15 minutes')
        ->and($recent->refresh()->status)->toBe(MailLogStatus::Pending)
        ->and($sent->refresh()->status)->toBe(MailLogStatus::Sent)
        ->and($group->refresh()->failed_count)->toBe(1);

    $this->artisan('mail-log:settle-stale', ['--minutes' => 3])->assertSuccessful();

    expect($recent->refresh()->status)->toBe(MailLogStatus::Failed);
});

it('rejects a settle-stale timeout below one minute', function (): void {
    $this->artisan('mail-log:settle-stale', ['--minutes' => 0])->assertFailed();
});

class FailingTransport implements TransportInterface
{
    public function __toString(): string
    {
        return 'failing://';
    }

    public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SentMessage
    {
        throw new TransportException('Connection refused by smtp.example.com:587');
    }
}

class SettlementOrderMail extends Mailable
{
    use HasMailLog;

    public function __construct(public Model $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Settlement — order shipped');
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
}

class QueuedWelcomeNotification extends BaseNotification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Welcome')->line('Thanks for signing up.');
    }
}
