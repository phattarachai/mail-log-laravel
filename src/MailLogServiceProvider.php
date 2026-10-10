<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Override;
use Phattarachai\MailLogLaravel\Console\InstallCommand;
use Phattarachai\MailLogLaravel\Console\ResendCommand;
use Phattarachai\MailLogLaravel\Console\SettleStaleCommand;
use Phattarachai\MailLogLaravel\Listeners\LogOutgoingMail;
use Phattarachai\MailLogLaravel\Support\Fingerprinter;

class MailLogServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mail-log.php', 'mail-log');

        $this->app->singleton(Fingerprinter::class);

        // Scoped, not singleton: the listener tracks this request's / job's
        // in-flight sends, and Octane + the queue worker flush scoped instances.
        $this->app->scoped(LogOutgoingMail::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/mail-log.php' => config_path('mail-log.php'),
        ], 'mail-log-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'mail-log-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/mail-log'),
        ], 'mail-log-views');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'mail-log');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'mail-log');

        if (config('mail-log.ui.path') !== null) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, ResendCommand::class, SettleStaleCommand::class]);
        }

        if (filter_var(config('mail-log.enabled'), FILTER_VALIDATE_BOOL)) {
            Event::listen(MessageSending::class, [LogOutgoingMail::class, 'handleSending']);
            Event::listen(MessageSent::class, [LogOutgoingMail::class, 'handleSent']);
            Event::listen(JobExceptionOccurred::class, [LogOutgoingMail::class, 'handleJobException']);
            Event::listen(JobProcessed::class, [LogOutgoingMail::class, 'handleJobProcessed']);
            Event::listen(JobFailed::class, [LogOutgoingMail::class, 'handleFailed']);

            $this->app->terminating(function (): void {
                if ($this->app->resolved(LogOutgoingMail::class)) {
                    $this->app->make(LogOutgoingMail::class)->handleTerminating();
                }
            });
        }
    }
}
