<?php

declare(strict_types=1);

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Phattarachai\MailLogLaravel\Models\MailLogGroup;

beforeEach(function (): void {
    config()->set('mail.default', 'array');
    config()->set('mail.mailers.array', ['transport' => 'array']);
    config()->set('mail.from', ['address' => 'app@example.com', 'name' => 'App']);

    ResetPassword::createUrlUsing(
        fn (object $notifiable, string $token): string => 'https://app.test/reset-password/'.$token.'?email=a%40example.com',
    );
});

afterEach(function (): void {
    ResetPassword::$createUrlCallback = null;
});

it('stores a reset-password mail without the recipient token, still grouped across tokens', function (): void {
    Notification::route('mail', 'a@example.com')->notify(new ResetPassword('first-secret-token'));
    Notification::route('mail', 'b@example.com')->notify(new ResetPassword('second-secret-token'));

    $group = MailLogGroup::query()->sole();

    expect($group->html_body)->toContain('/reset-password/[redacted]')
        ->not->toContain('first-secret-token')
        ->and($group->text_body)->not->toContain('first-secret-token')
        ->and($group->sent_count)->toBe(2);
});

it('stores the body verbatim when redaction is turned off', function (): void {
    config()->set('mail-log.redact.enabled', value: false);

    Notification::route('mail', 'a@example.com')->notify(new ResetPassword('visible-token'));

    expect(MailLogGroup::query()->sole()->html_body)->toContain('visible-token');
});
