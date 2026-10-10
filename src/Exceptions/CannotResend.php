<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Exceptions;

use RuntimeException;
use Throwable;

class CannotResend extends RuntimeException
{
    public static function notStored(): self
    {
        return new self('This send can\'t be resent: its Mailable doesn\'t opt in with mailLogResendable(), or the send was logged before resend existed.');
    }

    public static function unreadable(Throwable $previous): self
    {
        return new self('The stored Mailable can\'t be restored (the APP_KEY changed, the class was renamed, or a model it uses was deleted): '.$previous->getMessage(), previous: $previous);
    }

    public static function noRecipients(): self
    {
        return new self('There is no one to resend to.');
    }

    public static function refused(): self
    {
        return new self('The resend was not sent: the Mailable adds recipients of its own (in envelope() or build()), so it can only be resent to them, or another MessageSending listener cancelled it.');
    }
}
