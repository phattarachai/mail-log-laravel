<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Mix into a Mailable (or a custom Notification-channel Mailable) so the
 * outgoing message carries the X-Mail-* metadata the Fingerprinter reads
 * to group sends. Hosts override the hook methods as needed; defaults
 * are safe for the common "Mailable + model" case.
 *
 * Usage:
 *
 *   class OrderShippedMail extends Mailable
 *   {
 *       use HasMailLog;
 *
 *       public function __construct(public Order $order) {}
 *
 *       protected function mailLogModel(): ?Model
 *       {
 *           return $this->order;
 *       }
 *
 *       public function headers(): Headers
 *       {
 *           return $this->withMailLog(new Headers());
 *       }
 *   }
 */
trait HasMailLog
{
    /**
     * Stamp every applicable X-Mail-* header onto the given Headers value.
     * Call from the Mailable's headers() method:
     *
     *     return $this->withMailLog(new Headers());
     */
    public function withMailLog(Headers $headers): Headers
    {
        if (! filter_var(config('mail-log.enabled', default: true), FILTER_VALIDATE_BOOL)) {
            return $headers;
        }

        $text = [
            'X-Mail-Class' => static::class,
        ];

        $notificationClass = $this->mailLogNotificationClass();

        if ($notificationClass !== null && $notificationClass !== '') {
            $text['X-Mail-Notification-Class'] = $notificationClass;
        }

        $model = $this->mailLogModel();

        if ($model instanceof Model && $model->exists) {
            $text['X-Mail-Model-Type'] = $model->getMorphClass();
            $text['X-Mail-Model-Id'] = (string) $model->getKey();
        }

        $models = $this->mailLogModelHeader();

        if ($models !== null) {
            $text['X-Mail-Models'] = $models;
        }

        $resendPayload = $this->mailLogResendPayload();

        if ($resendPayload !== null) {
            $text['X-Mail-Log-Resend'] = $resendPayload;
        }

        $hints = $this->mailLogFingerprintHints();

        if ($hints !== []) {
            $text['X-Mail-Fingerprint-Hint'] = json_encode(
                $hints,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        }

        $mode = $this->mailLogFingerprintMode();

        if (is_array($mode) && $mode !== []) {
            $text['X-Mail-Fingerprint-Mode'] = json_encode(
                array_values($mode),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        }

        if ($this->mailLogSkip()) {
            $text['X-Mail-Log-Skip'] = '1';
        }

        $headers->text = array_merge($headers->text, $text);

        return $headers;
    }

    /**
     * Eloquent model that originated this mail. Returned model is folded
     * into the fingerprint when the active mode includes 'model'.
     */
    protected function mailLogModel(): ?Model
    {
        return null;
    }

    /**
     * Further models this send relates to (a customer, a shipment). They are
     * stored per send next to mailLogModel() so the dashboard can find and
     * link the mail from any of them, but they never change the grouping.
     *
     * @return iterable<int, Model|null>
     */
    protected function mailLogModels(): iterable
    {
        return [];
    }

    /**
     * Opt in to resending from the dashboard or `mail-log:resend`. The
     * Mailable is stored per send (serialized like a queued job, models as
     * identifiers, encrypted) and rendered fresh on resend. Leave false for
     * mail carrying one-time secrets such as login or reset tokens.
     */
    protected function mailLogResendable(): bool
    {
        return false;
    }

    /**
     * Host returns the Notification class when the Mailable is constructed
     * inside a Notification's toMail(). Folded into the fingerprint when
     * the active mode includes 'notification_class'.
     */
    protected function mailLogNotificationClass(): ?string
    {
        return null;
    }

    /**
     * Free-form strings added to the fingerprint when the active mode
     * includes 'hints'. Order is normalized by the Fingerprinter, so
     * hosts don't need to worry about sorting.
     *
     * @return array<int|string, scalar|null>
     */
    protected function mailLogFingerprintHints(): array
    {
        return [];
    }

    /**
     * Override the global fingerprint mode for this Mailable. Return null
     * to fall back to config('mail-log.fingerprint.default_mode').
     *
     * Valid entries: 'class' | 'notification_class' | 'model' | 'hints'
     *              | 'subject' | 'body' | 'mailer'.
     *
     * @return array<int, string>|null
     */
    protected function mailLogFingerprintMode(): ?array
    {
        return null;
    }

    /**
     * Opt this send out of MailLog capture entirely (e.g. health-check
     * pings, transactional one-offs that would dirty the dedup view).
     */
    protected function mailLogSkip(): bool
    {
        return false;
    }

    /**
     * JSON list of [morph type, key] pairs for mailLogModels(), or null.
     */
    private function mailLogModelHeader(): ?string
    {
        $pairs = [];

        foreach ($this->mailLogModels() as $model) {
            if ($model instanceof Model && $model->exists) {
                $pairs[] = [$model->getMorphClass(), (string) $model->getKey()];
            }
        }

        if ($pairs === []) {
            return null;
        }

        return json_encode(array_values(array_unique($pairs, SORT_REGULAR)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The encrypted, serialized Mailable for resend, or null when this
     * Mailable doesn't opt in, can't be serialized (e.g. it holds a closure),
     * or exceeds mail-log.resend.max_payload_bytes.
     */
    private function mailLogResendPayload(): ?string
    {
        if (! $this->mailLogResendable()) {
            return null;
        }

        try {
            $payload = Crypt::encryptString(serialize($this));
        } catch (Throwable) {
            return null;
        }

        $maxBytes = (int) config('mail-log.resend.max_payload_bytes', 64 * 1024);

        return strlen($payload) <= $maxBytes ? $payload : null;
    }
}
