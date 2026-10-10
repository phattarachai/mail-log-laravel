<?php

declare(strict_types=1);

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Phattarachai\MailLogLaravel\Http\Middleware\Authorize;
use Phattarachai\MailLogLaravel\Listeners\LogOutgoingMail;
use Phattarachai\MailLogLaravel\MailLog;
use Phattarachai\MailLogLaravel\MailLogServiceProvider;
use Phattarachai\MailLogLaravel\Support\Fingerprinter;

it('merges the package config', function (): void {
    expect(config('mail-log.tables.groups'))->toBe('mail_log_groups')
        ->and(config('mail-log.tables.events'))->toBe('mail_logs')
        ->and(config('mail-log.fingerprint.default_mode'))->toBe(['class', 'model'])
        ->and(config('mail-log.ui.middleware'))->toContain('web')
        ->and(config('mail-log.ui.middleware'))->toContain(Authorize::class);
});

it('registers the Fingerprinter as a singleton', function (): void {
    expect(app(Fingerprinter::class))
        ->toBeInstanceOf(Fingerprinter::class)
        ->and(app(Fingerprinter::class))->toBe(app(Fingerprinter::class));
});

it('wires the outgoing-mail event listeners when enabled', function (): void {
    expect(Event::hasListeners(MessageSending::class))->toBeTrue()
        ->and(Event::hasListeners(MessageSent::class))->toBeTrue()
        ->and(Event::hasListeners(JobFailed::class))->toBeTrue();
});

it('returns 403 from the dashboard by default (debug-only gate)', function (): void {
    $response = $this->get('/mail-log');

    $response->assertForbidden();
});

it('allows the dashboard once MailLog::auth approves the request', function (): void {
    MailLog::auth(fn () => true);

    $this->get('/mail-log')->assertOk();
});

it('honors MailLog::auth returning false', function (): void {
    MailLog::auth(fn () => false);

    $this->get('/mail-log')->assertForbidden();
});

it('falls back to APP_DEBUG when no auth callback is registered', function (): void {
    config()->set('app.debug', value: true);

    $this->get('/mail-log')->assertOk();
});

it('exposes a mail-log:install Artisan command', function (): void {
    $this->artisan('mail-log:install', ['--dry-run' => true])->assertSuccessful();
});

it('registers the package publish tags', function (): void {
    $tags = ServiceProvider::publishableGroups();

    expect($tags)->toContain('mail-log-config')
        ->and($tags)->toContain('mail-log-migrations')
        ->and($tags)->toContain('mail-log-views');
});

it('exposes the LogOutgoingMail listener handlers', function (): void {
    $listener = app(LogOutgoingMail::class);

    expect(method_exists($listener, 'handleSending'))->toBeTrue()
        ->and(method_exists($listener, 'handleSent'))->toBeTrue()
        ->and(method_exists($listener, 'handleFailed'))->toBeTrue();
});

it('parses MAIL_LOG_ENABLED from the environment as a boolean', function (string $value, bool $expected): void {
    Env::getRepository()->set('MAIL_LOG_ENABLED', $value);

    try {
        $config = require __DIR__.'/../../config/mail-log.php';
    } finally {
        Env::getRepository()->clear('MAIL_LOG_ENABLED');
    }

    expect($config['enabled'])->toBe($expected);
})->with([
    ['1', true],
    ['true', true],
    ['on', true],
    ['0', false],
    ['false', false],
]);

it('registers the listeners for any truthy enabled value, including a string from an older published config', function (mixed $enabled, bool $expected): void {
    Event::forget(MessageSending::class);
    config()->set('mail-log.enabled', $enabled);

    new MailLogServiceProvider($this->app)->boot();

    expect(Event::hasListeners(MessageSending::class))->toBe($expected);
})->with([
    ['1', true],
    [true, true],
    ['0', false],
    [false, false],
]);
