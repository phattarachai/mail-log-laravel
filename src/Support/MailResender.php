<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Phattarachai\MailLogLaravel\Exceptions\CannotResend;
use Phattarachai\MailLogLaravel\Listeners\LogOutgoingMail;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Resends a logged send by restoring the Mailable stored for it (opt-in via
 * HasMailLog::mailLogResendable()) and sending it now. The content renders
 * fresh, so links are current, and the new send is logged in the same way
 * as any other, marked as a resend of the original.
 */
class MailResender
{
    /**
     * @param  list<string>|null  $recipients  addresses to send to; null resends to the original to / cc / bcc
     *
     * @throws CannotResend
     */
    public function resend(MailLog $event, ?array $recipients = null): void
    {
        $mailable = $this->restore($event);

        [$to, $cc, $bcc] = $recipients === null
            ? [$this->addresses($event->to), $this->addresses($event->cc ?? []), $this->addresses($event->bcc ?? [])]
            : [$this->addresses($recipients), [], []];

        if ($to === [] && $cc === [] && $bcc === []) {
            throw CannotResend::noRecipients();
        }

        // The restored Mailable still lists the original recipients.
        $mailable->to = [];
        $mailable->cc = [];
        $mailable->bcc = [];

        $allowed = array_map(fn (array $address): string => $address['email'], [...$to, ...$cc, ...$bcc]);
        $listener = app(LogOutgoingMail::class);
        $listener->expectResend($event->id, $allowed);

        try {
            $sent = Mail::to($to)->cc($cc)->bcc($bcc)->sendNow($mailable);
        } finally {
            $listener->forgetResend();
        }

        if ($sent === null) {
            throw CannotResend::refused();
        }
    }

    /**
     * @throws CannotResend
     */
    private function restore(MailLog $event): Mailable
    {
        if ($event->resend_payload === null) {
            throw CannotResend::notStored();
        }

        try {
            $mailable = unserialize(Crypt::decryptString($event->resend_payload));
        } catch (Throwable $e) {
            throw CannotResend::unreadable($e);
        }

        if (! $mailable instanceof Mailable) {
            throw CannotResend::notStored();
        }

        return $mailable;
    }

    /**
     * Stored recipients look like "Name <a@example.com>" or "a@example.com".
     *
     * @param  array<int, string>  $recipients
     * @return list<array{email: string, name: string|null}>
     */
    private function addresses(array $recipients): array
    {
        $addresses = [];

        foreach ($recipients as $recipient) {
            $address = Address::create(trim($recipient));
            $addresses[mb_strtolower($address->getAddress())] = [
                'email' => mb_strtolower($address->getAddress()),
                'name' => $address->getName() !== '' ? $address->getName() : null,
            ];
        }

        return array_values($addresses);
    }
}
