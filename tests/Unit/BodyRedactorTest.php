<?php

declare(strict_types=1);

use Phattarachai\MailLogLaravel\Support\BodyRedactor;

it('masks reset tokens, verify hashes and signed-url query values', function (string $body, string $expected): void {
    expect((new BodyRedactor)($body))->toBe($expected);
})->with([
    'reset-password path' => [
        '<a href="https://app.test/reset-password/7f3c9a1b?email=a%40example.com">Reset</a>',
        '<a href="https://app.test/reset-password/[redacted]?email=a%40example.com">Reset</a>',
    ],
    'password/reset path' => [
        'https://app.test/password/reset/7f3c9a1b',
        'https://app.test/password/reset/[redacted]',
    ],
    'verify url with html-escaped query' => [
        '<a href="https://app.test/email/verify/5/deadbeef?expires=1700000000&amp;signature=abc123">Verify</a>',
        '<a href="https://app.test/email/verify/5/[redacted]?expires=[redacted]&amp;signature=[redacted]">Verify</a>',
    ],
    'verify-email path' => [
        'https://app.test/verify-email/42/cafe01',
        'https://app.test/verify-email/42/[redacted]',
    ],
    'magic link token query' => [
        'https://app.test/login/magic?token=s3cr3t&next=/admin',
        'https://app.test/login/magic?token=[redacted]&next=/admin',
    ],
]);

it('leaves the body untouched when redaction is disabled', function (): void {
    config()->set('mail-log.redact.enabled', value: false);

    $body = 'https://app.test/reset-password/7f3c9a1b';

    expect((new BodyRedactor)($body))->toBe($body);
});

it('withholds the body instead of storing it when a pattern fails to apply', function (): void {
    config()->set('mail-log.redact.patterns', ['/(?:\D+|<\d+>)*[!?]/' => '']);

    $result = (new BodyRedactor)(str_repeat('foobar foobar foobar ', 2000));

    expect($result)->toStartWith('[body withheld');
});
