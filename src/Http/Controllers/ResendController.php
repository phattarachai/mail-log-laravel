<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Phattarachai\MailLogLaravel\Exceptions\CannotResend;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;
use Phattarachai\MailLogLaravel\Support\MailResender;

class ResendController
{
    public function store(Request $request, MailLogGroup $group, MailLog $event, MailResender $resender): RedirectResponse
    {
        abort_unless(filter_var(config('mail-log.resend.enabled', default: true), FILTER_VALIDATE_BOOL), 404);
        abort_unless($event->group_id === $group->id, 404);

        $recipients = preg_split('/[\s,;]+/', trim((string) $request->input('recipients', '')), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        Validator::make(['recipients' => $recipients], [
            'recipients' => ['array', 'max:10'],
            'recipients.*' => ['email'],
        ], ['recipients.*.email' => 'Each recipient must be a valid email address.'])->validate();

        try {
            $resender->resend($event, $recipients === [] ? null : $recipients);
        } catch (CannotResend $e) {
            return back()->with('mail-log:error', $e->getMessage());
        }

        $sentTo = $recipients === [] ? 'the original recipients' : implode(', ', $recipients);

        return back()->with('mail-log:flash', "Resent send #{$event->id} to {$sentTo}.");
    }
}
