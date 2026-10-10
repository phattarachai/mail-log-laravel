<?php

declare(strict_types=1);

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Mail;
use Phattarachai\MailLogLaravel\Concerns\HasMailLog;
use Phattarachai\MailLogLaravel\Enums\MailLogStatus;
use Phattarachai\MailLogLaravel\Listeners\LogOutgoingMail;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;

beforeEach(function (): void {
    Relation::morphMap([], merge: false);
    MailLogGroup::registerMorphMap();

    config()->set('mail.default', 'array');
    config()->set('mail.mailers.array', ['transport' => 'array']);
    config()->set('mail.from', ['address' => 'app@example.com', 'name' => 'App']);
});

it('flips the event to SENT and bumps the group sent_count on MessageSent', function (): void {
    $order = MailLogGroup::factory()->create();

    Mail::to('a@example.com')->send(new OutcomeOrderMail($order));

    $group = MailLogGroup::query()
        ->where('mailable_class', OutcomeOrderMail::class)
        ->firstOrFail();

    $event = MailLog::query()->where('group_id', $group->id)->firstOrFail();

    expect($event->status)->toBe(MailLogStatus::Sent)
        ->and($event->sent_at)->not->toBeNull()
        ->and($group->sent_count)->toBe(1)
        ->and($group->failed_count)->toBe(0)
        ->and($group->latest_status)->toBe(MailLogStatus::Sent);
});

it('flips the event to FAILED and bumps failed_count on JobFailed', function (): void {
    $order = MailLogGroup::factory()->create();
    $group = MailLogGroup::factory()->create([
        'mailable_class' => OutcomeOrderMail::class,
        'model_type' => 'mail_log_group',
        'model_id' => (string) $order->id,
        'sent_count' => 0,
        'failed_count' => 0,
        'latest_status' => MailLogStatus::Pending,
    ]);

    $row = MailLog::factory()->for($group, 'group')->pending()->create();

    $mailable = new OutcomeOrderMail($order);
    $queued = new SendQueuedMailable($mailable);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn(null);
    $job->shouldReceive('payload')->andReturn([
        'data' => [
            'commandName' => SendQueuedMailable::class,
            'command' => serialize($queued),
        ],
    ]);

    $jobFailed = new JobFailed('sync', $job, new RuntimeException('SMTP timeout'));

    app(LogOutgoingMail::class)->handleFailed($jobFailed);

    $row->refresh();
    $group->refresh();

    expect($row->status)->toBe(MailLogStatus::Failed)
        ->and($row->error_message)->toContain('SMTP timeout')
        ->and($group->failed_count)->toBe(1)
        ->and($group->sent_count)->toBe(0)
        ->and($group->latest_status)->toBe(MailLogStatus::Failed);
});

it('reflects the most recent event status when mixed outcomes are recorded', function (): void {
    $order = MailLogGroup::factory()->create();

    Mail::to('a@example.com')->send(new OutcomeOrderMail($order));
    Mail::to('b@example.com')->send(new OutcomeOrderMail($order));
    Mail::to('c@example.com')->send(new OutcomeOrderMail($order));

    $group = MailLogGroup::query()
        ->where('mailable_class', OutcomeOrderMail::class)
        ->firstOrFail();

    expect($group->sent_count)->toBe(3)
        ->and($group->latest_status)->toBe(MailLogStatus::Sent);

    $row = MailLog::factory()->for($group, 'group')->pending()->create();

    $mailable = new OutcomeOrderMail($order);
    $queued = new SendQueuedMailable($mailable);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('uuid')->andReturn(null);
    $job->shouldReceive('payload')->andReturn([
        'data' => [
            'commandName' => SendQueuedMailable::class,
            'command' => serialize($queued),
        ],
    ]);

    $jobFailed = new JobFailed('sync', $job, new RuntimeException('boom'));

    app(LogOutgoingMail::class)->handleFailed($jobFailed);

    $group->refresh();

    expect($group->sent_count)->toBe(3)
        ->and($group->failed_count)->toBe(1)
        ->and($group->latest_status)->toBe(MailLogStatus::Failed);
});

it('calculates seconds from the X-Mail-Log-Start header set during handleSending', function (): void {
    $order = MailLogGroup::factory()->create();

    Mail::to('a@example.com')->send(new OutcomeOrderMail($order));

    $event = MailLog::query()
        ->whereHas('group', fn (Builder $q) => $q->where('mailable_class', OutcomeOrderMail::class))
        ->firstOrFail();

    expect($event->seconds)->toBeFloat()
        ->and($event->seconds)->toBeGreaterThanOrEqual(0.0)
        ->and($event->seconds)->toBeLessThan(5.0);
});

class OutcomeOrderMail extends Mailable
{
    use HasMailLog;

    public function __construct(public Model $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Outcome — order shipped');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Outcome — order shipped</p>');
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
