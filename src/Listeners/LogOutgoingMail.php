<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Listeners;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Phattarachai\MailLogLaravel\Enums\MailLogStatus;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;
use Phattarachai\MailLogLaravel\Support\BodyRedactor;
use Phattarachai\MailLogLaravel\Support\Fingerprinter;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Throwable;

/**
 * Bound as a scoped singleton: it remembers the events this process created
 * whose send has not confirmed yet ("in flight"), so a failure signal raised
 * later in the same request or job settles exactly those rows.
 */
class LogOutgoingMail
{
    public const string UNCONFIRMED_MESSAGE = 'No MessageSent was fired for this send: the transport threw, or another MessageSending listener cancelled it. Check the application log for the exception.';

    /**
     * Event ids created by this process that are still waiting for MessageSent.
     *
     * @var array<int, int>
     */
    private array $inFlight = [];

    /**
     * Uuids of jobs whose failure this process already settled from $inFlight.
     *
     * @var array<string, true>
     */
    private array $settledJobs = [];

    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly BodyRedactor $redactor,
    ) {}

    public function handleSending(MessageSending $event): ?bool
    {
        if (! filter_var(config('mail-log.enabled', default: true), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $message = $event->message;

        if ($this->headerValue($message, 'X-Mail-Log-Skip') === '1') {
            return null;
        }

        $mailer = $event->data['mailer'] ?? null;

        try {
            $fingerprint = ($this->fingerprinter)($message, $mailer);

            $eventRow = DB::transaction(function () use ($message, $fingerprint, $mailer): MailLog {
                $group = $this->resolveGroup($fingerprint, $message, $mailer);

                return MailLog::query()->create([
                    'group_id' => $group->id,
                    'to' => $this->extractAddresses($message->getTo()),
                    'cc' => $this->extractAddresses($message->getCc()) ?: null,
                    'bcc' => $this->extractAddresses($message->getBcc()) ?: null,
                    'status' => MailLogStatus::Pending,
                ]);
            });

            $this->inFlight[$eventRow->id] = $eventRow->id;

            $headers = $message->getHeaders();
            $headers->addTextHeader('X-Mail-Log-Event-Id', (string) $eventRow->id);
            $headers->addTextHeader('X-Mail-Log-Start', (string) microtime(as_float: true));
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    public function handleSent(MessageSent $event): void
    {
        try {
            $message = $event->message;
            $eventId = $this->headerValue($message, 'X-Mail-Log-Event-Id');

            if ($eventId === null) {
                return;
            }

            unset($this->inFlight[(int) $eventId]);

            $row = MailLog::query()->find($eventId);

            if ($row === null) {
                return;
            }

            DB::transaction(function () use ($row, $message): void {
                $row->update([
                    'status' => MailLogStatus::Sent,
                    'sent_at' => now(),
                    'seconds' => $this->calculatedSeconds($message),
                ]);

                $row->group?->recordEvent($row);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Fires on every failed attempt of every job, after JobFailed on the last
     * one. Mail the attempt sent but never confirmed is settled as Failed, so
     * a retried job leaves one Failed row per failed attempt instead of a
     * trail of Pending ones.
     */
    public function handleJobException(JobExceptionOccurred $event): void
    {
        $isFinalAttempt = $event->job->hasFailed() || $event->job instanceof SyncJob;

        $message = $isFinalAttempt
            ? $event->exception->getMessage()
            : sprintf('Attempt %d failed, the job will retry: %s', $event->job->attempts(), $event->exception->getMessage());

        if ($this->settleInFlight($message)) {
            $this->rememberSettled($event->job->uuid());
        }
    }

    /**
     * A job finished without throwing, but a send inside it may still have
     * thrown and been caught by the job itself.
     */
    public function handleJobProcessed(JobProcessed $event): void
    {
        $this->settleInFlight(self::UNCONFIRMED_MESSAGE);
    }

    /**
     * Any queued job (SendQueuedMailable, SendQueuedNotifications, or the host's
     * own job) that sent mail in this process and then failed settles those
     * sends. When nothing is in flight here (the sending attempt ran in a
     * worker that died, e.g. on a timeout), a queued Mailable falls back to
     * the newest Pending event of its class + model.
     */
    public function handleFailed(JobFailed $event): void
    {
        $uuid = $event->job->uuid();

        if ($this->settleInFlight($event->exception->getMessage())) {
            $this->rememberSettled($uuid);

            return;
        }

        if ($uuid !== null && isset($this->settledJobs[$uuid])) {
            return;
        }

        $row = $this->pendingEventForQueuedMailable($event->job->payload());

        if (! $row instanceof MailLog) {
            return;
        }

        try {
            $row->markFailed($event->exception->getMessage());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * End of an HTTP request or console command: a synchronous Mail::send()
     * that threw in the transport fires no event, so whatever is still in
     * flight did not go out.
     */
    public function handleTerminating(): void
    {
        $this->settleInFlight(self::UNCONFIRMED_MESSAGE);
    }

    /**
     * @return bool whether anything was in flight
     */
    private function settleInFlight(string $message): bool
    {
        if ($this->inFlight === []) {
            return false;
        }

        $ids = array_values($this->inFlight);
        $this->inFlight = [];

        try {
            MailLog::query()
                ->whereKey($ids)
                ->where('status', MailLogStatus::Pending)
                ->get()
                ->each(fn (MailLog $row) => $row->markFailed($message));
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    private function rememberSettled(?string $uuid): void
    {
        if ($uuid !== null) {
            $this->settledJobs[$uuid] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pendingEventForQueuedMailable(array $payload): ?MailLog
    {
        if (($payload['data']['commandName'] ?? null) !== SendQueuedMailable::class) {
            return null;
        }

        try {
            $command = unserialize($payload['data']['command']);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        // headers() is the hook HasMailLog fills; the base Mailable has none.
        if (! $command instanceof SendQueuedMailable || ! method_exists($command->mailable, 'headers')) {
            return null;
        }

        try {
            $headers = $command->mailable->headers();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $mailableClass = $headers->text['X-Mail-Class'] ?? null;
        $modelType = $headers->text['X-Mail-Model-Type'] ?? null;
        $modelId = $headers->text['X-Mail-Model-Id'] ?? null;

        if ($mailableClass === null || $modelType === null || $modelId === null) {
            return null;
        }

        return MailLog::query()
            ->where('status', MailLogStatus::Pending)
            ->whereHas('group', function (Builder $query) use ($mailableClass, $modelType, $modelId): void {
                $query->where('mailable_class', $mailableClass)
                    ->where('model_type', $modelType)
                    ->where('model_id', $modelId);
            })
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    private function resolveGroup(string $fingerprint, Email $message, ?string $mailer): MailLogGroup
    {
        $canonical = $this->canonicalAttributes($message, $mailer);

        try {
            $group = MailLogGroup::query()->firstOrCreate(['fingerprint' => $fingerprint], $canonical + [
                'html_body' => ($this->redactor)($this->bodyString($message->getHtmlBody())),
                'text_body' => ($this->redactor)($this->bodyString($message->getTextBody())),
                'latest_status' => MailLogStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            $group = MailLogGroup::query()->where('fingerprint', $fingerprint)->firstOrFail();
        }

        if ($group->wasRecentlyCreated) {
            $this->storeAttachments($group, $message);

            return $group;
        }

        $group->forceFill($canonical)->save();

        return $group;
    }

    /**
     * @return array{subject: string|null, from: string|null, mailable_class: string|null, notification_class: string|null, model_type: string|null, model_id: string|null, mailer: string|null}
     */
    private function canonicalAttributes(Email $message, ?string $mailer): array
    {
        return [
            'subject' => $message->getSubject(),
            'from' => $this->formatAddresses($message->getFrom()),
            'mailable_class' => $this->headerValue($message, 'X-Mail-Class'),
            'notification_class' => $this->headerValue($message, 'X-Mail-Notification-Class'),
            'model_type' => $this->headerValue($message, 'X-Mail-Model-Type'),
            'model_id' => $this->headerValue($message, 'X-Mail-Model-Id'),
            'mailer' => $mailer,
        ];
    }

    private function headerValue(Email $message, string $name): ?string
    {
        $header = $message->getHeaders()->get($name);

        if (! $header instanceof HeaderInterface) {
            return null;
        }

        $value = $header->getBodyAsString();

        return $value === '' ? null : $value;
    }

    /**
     * @param  Address[]  $addresses
     * @return string[]
     */
    private function extractAddresses(array $addresses): array
    {
        $cap = (int) config('mail-log.fingerprint.max_recipients_per_event', 200);

        $formatted = array_map(
            fn (Address $address): string => $address->getName() !== ''
                ? "{$address->getName()} <{$address->getAddress()}>"
                : $address->getAddress(),
            $addresses,
        );

        return array_slice($formatted, 0, $cap);
    }

    /**
     * @param  Address[]  $addresses
     */
    private function formatAddresses(array $addresses): ?string
    {
        if ($addresses === []) {
            return null;
        }

        return implode(', ', $this->extractAddresses($addresses));
    }

    private function storeAttachments(MailLogGroup $group, Email $message): void
    {
        $maxBytes = (int) config('mail-log.attachments.max_bytes_each', 10 * 1024 * 1024);
        $collection = (string) config('mail-log.attachments.collection', 'attachments');

        foreach ($message->getAttachments() as $attachment) {
            $body = $attachment->getBody();

            if (strlen($body) > $maxBytes) {
                continue;
            }

            $filename = $attachment->getFilename() ?? 'attachment';
            $tempPath = tempnam(sys_get_temp_dir(), 'mail_attachment_');
            file_put_contents($tempPath, $body);

            $group->addMedia($tempPath)
                ->usingFileName($filename)
                ->toMediaCollection($collection);
        }
    }

    /**
     * Symfony bodies may be a string or a stream resource.
     *
     * @param  resource|string|null  $body
     */
    private function bodyString(mixed $body): ?string
    {
        if (is_resource($body)) {
            return (string) stream_get_contents($body, offset: 0);
        }

        return $body;
    }

    private function calculatedSeconds(Email $message): ?float
    {
        $start = $this->headerValue($message, 'X-Mail-Log-Start');

        if ($start === null) {
            return null;
        }

        return round(microtime(as_float: true) - (float) $start, 3);
    }
}
