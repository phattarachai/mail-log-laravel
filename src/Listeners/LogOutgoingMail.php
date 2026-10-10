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
use Phattarachai\MailLogLaravel\Models\MailLogAssociation;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;
use Phattarachai\MailLogLaravel\Support\BodyRedactor;
use Phattarachai\MailLogLaravel\Support\Fingerprinter;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\IdentificationHeader;
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
     * The X-Mail-* headers HasMailLog stamps. They carry class names, model
     * keys and the encrypted resend payload, so they are read and then
     * removed before the message reaches the transport.
     *
     * @var list<string>
     */
    public const array LOG_HEADERS = [
        'X-Mail-Class', 'X-Mail-Notification-Class', 'X-Mail-Model-Type', 'X-Mail-Model-Id',
        'X-Mail-Models', 'X-Mail-Fingerprint', 'X-Mail-Fingerprint-Mode', 'X-Mail-Fingerprint-Hint',
        'X-Mail-Log-Skip', 'X-Mail-Log-Resend', 'X-Mail-Log-Event-Id', 'X-Mail-Log-Start',
    ];

    /**
     * Event ids created by this process that are still waiting for
     * MessageSent, with the microtime the send started.
     *
     * @var array<int, float>
     */
    private array $inFlight = [];

    /**
     * Message-ID => event id for the sends in $inFlight.
     *
     * @var array<string, int>
     */
    private array $inFlightByMessageId = [];

    /**
     * Uuids of jobs whose failure this process already settled from $inFlight.
     *
     * @var array<string, true>
     */
    private array $settledJobs = [];

    /**
     * Set by ResendMail around its sendNow(): the event being resent and the
     * only addresses the resend may reach.
     *
     * @var array{from: int, recipients: list<string>}|null
     */
    private ?array $pendingResend = null;

    public function __construct(
        private readonly Fingerprinter $fingerprinter,
        private readonly BodyRedactor $redactor,
    ) {}

    public function handleSending(MessageSending $event): ?bool
    {
        $message = $event->message;

        try {
            return $this->logSending($message, $event->data['mailer'] ?? null);
        } finally {
            foreach (self::LOG_HEADERS as $name) {
                $message->getHeaders()->remove($name);
            }
        }
    }

    public function handleSent(MessageSent $event): void
    {
        try {
            $message = $event->message;
            $messageId = $this->messageIdOf($message);

            if ($messageId === null) {
                return;
            }

            $row = $this->sentRow($messageId);

            if (! $row instanceof MailLog) {
                return;
            }

            $startedAt = $this->inFlight[$row->id] ?? null;
            unset($this->inFlight[$row->id], $this->inFlightByMessageId[$messageId]);

            DB::transaction(function () use ($row, $startedAt): void {
                $row->update([
                    'status' => MailLogStatus::Sent,
                    'sent_at' => now(),
                    'seconds' => $startedAt === null ? null : round(microtime(as_float: true) - $startedAt, 3),
                ]);

                $row->group?->recordEvent($row);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Called by ResendMail: the next send must only reach $recipients and is
     * recorded as a resend of $fromEventId.
     *
     * @param  list<string>  $recipients
     */
    public function expectResend(int $fromEventId, array $recipients): void
    {
        $this->pendingResend = [
            'from' => $fromEventId,
            'recipients' => array_map(mb_strtolower(...), $recipients),
        ];
    }

    public function forgetResend(): void
    {
        $this->pendingResend = null;
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

    private function logSending(Email $message, ?string $mailer): ?bool
    {
        if (! filter_var(config('mail-log.enabled', default: true), FILTER_VALIDATE_BOOL)) {
            return null;
        }

        if ($this->headerValue($message, 'X-Mail-Log-Skip') === '1') {
            return null;
        }

        $resend = $this->pendingResend;

        if ($resend !== null && ! $this->onlyAddresses($message, $resend['recipients'])) {
            return false;
        }

        try {
            $fingerprint = ($this->fingerprinter)($message, $mailer);
            $messageId = $this->ensureMessageId($message);

            $eventRow = DB::transaction(function () use ($message, $fingerprint, $mailer, $messageId, $resend): MailLog {
                $group = $this->resolveGroup($fingerprint, $message, $mailer);

                $row = MailLog::query()->create([
                    'group_id' => $group->id,
                    'to' => $this->extractAddresses($message->getTo()),
                    'cc' => $this->extractAddresses($message->getCc()) ?: null,
                    'bcc' => $this->extractAddresses($message->getBcc()) ?: null,
                    'status' => MailLogStatus::Pending,
                    'message_id' => $messageId,
                    'resend_payload' => $this->headerValue($message, 'X-Mail-Log-Resend'),
                    'resent_from_id' => $resend['from'] ?? null,
                ]);

                $this->storeAssociations($row, $message);

                return $row;
            });

            $this->inFlight[$eventRow->id] = microtime(as_float: true);
            $this->inFlightByMessageId[$messageId] = $eventRow->id;
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    /**
     * The host's own Message-ID when it set one, else one assigned now so the
     * send can be matched on MessageSent (and later against bounces).
     */
    private function ensureMessageId(Email $message): string
    {
        $existing = $this->messageIdOf($message);

        if ($existing !== null) {
            return $existing;
        }

        try {
            $id = $message->generateMessageId();
        } catch (Throwable) {
            $id = bin2hex(random_bytes(16)).'@mail-log.local';
        }

        $message->getHeaders()->addIdHeader('Message-ID', $id);

        return $id;
    }

    private function messageIdOf(Email $message): ?string
    {
        $header = $message->getHeaders()->get('Message-ID');

        return $header instanceof IdentificationHeader ? $header->getId() : null;
    }

    private function sentRow(string $messageId): ?MailLog
    {
        $id = $this->inFlightByMessageId[$messageId] ?? null;

        if ($id !== null) {
            return MailLog::query()->find($id);
        }

        return MailLog::query()
            ->where('message_id', $messageId)
            ->where('status', MailLogStatus::Pending)
            ->latest('id')
            ->first();
    }

    /**
     * @param  list<string>  $allowed  lower-cased addresses
     */
    private function onlyAddresses(Email $message, array $allowed): bool
    {
        $addresses = [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()];

        foreach ($addresses as $address) {
            if (! in_array(mb_strtolower($address->getAddress()), $allowed, strict: true)) {
                return false;
            }
        }

        return $addresses !== [];
    }

    /**
     * The primary model (mailLogModel()) plus mailLogModels(), one row each.
     */
    private function storeAssociations(MailLog $row, Email $message): void
    {
        $pairs = [];
        $primaryType = $this->headerValue($message, 'X-Mail-Model-Type');
        $primaryId = $this->headerValue($message, 'X-Mail-Model-Id');

        if ($primaryType !== null && $primaryId !== null) {
            $pairs[$primaryType."\0".$primaryId] = [$primaryType, $primaryId, true];
        }

        foreach ($this->extraModels($message) as [$type, $id]) {
            $pairs[$type."\0".$id] ??= [$type, $id, false];
        }

        if ($pairs === []) {
            return;
        }

        MailLogAssociation::query()->insert(array_map(fn (array $pair): array => [
            'event_id' => $row->id,
            'model_type' => $pair[0],
            'model_id' => $pair[1],
            'is_primary' => $pair[2],
        ], array_values($pairs)));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function extraModels(Email $message): array
    {
        $raw = $this->headerValue($message, 'X-Mail-Models');

        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, associative: true);

        if (! is_array($decoded)) {
            return [];
        }

        $pairs = [];

        foreach ($decoded as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null) && is_scalar($pair[1] ?? null)) {
                $pairs[] = [$pair[0], (string) $pair[1]];
            }
        }

        return $pairs;
    }

    /**
     * @return bool whether anything was in flight
     */
    private function settleInFlight(string $message): bool
    {
        if ($this->inFlight === []) {
            return false;
        }

        $ids = array_keys($this->inFlight);
        $this->inFlight = [];
        $this->inFlightByMessageId = [];

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
}
