<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Phattarachai\MailLogLaravel\Mail\TestMail;

class TestSendController
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless((bool) config('mail-log.test_send.enabled', default: true), 404);

        $maxBytes = (int) config('mail-log.attachments.max_bytes_each', 10 * 1024 * 1024);
        $maxKilobytes = max(1, (int) floor($maxBytes / 1024));

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'message' => ['nullable', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', "max:{$maxKilobytes}"],
        ]);

        $files = [];

        foreach ($request->file('attachments') ?? [] as $file) {
            $files[] = [
                'path' => $file->getRealPath(),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
            ];
        }

        $mailable = new TestMail(
            $validated['message'] ?? 'This is a test message dispatched from the Mail Log dashboard.',
            $files,
        );

        Mail::to($validated['email'])->send($mailable);

        return to_route('mail-log.index')
            ->with('mail-log:flash', 'Sent a test email to '.$validated['email'].'.');
    }
}
