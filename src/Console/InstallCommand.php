<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Phattarachai\MailLogLaravel\Support\EnvWriter;
use Symfony\Component\Console\Output\OutputInterface;

class InstallCommand extends Command
{
    /**
     * Env keys written via setIfAbsent so re-running install never overwrites
     * a value the operator has already tuned.
     *
     * @var array<string, string>
     */
    private const array ENV_DEFAULTS = [
        'MAIL_LOG_ENABLED' => 'true',
        'MAIL_LOG_RETENTION_DAYS' => '365',
        'MAIL_LOG_UI_PATH' => 'mail-log',
    ];

    protected $signature = 'mail-log:install
        {--dry-run : Print intended changes without writing files}';

    protected $description = 'Install Mail Log: publish config + migrations, write env keys, print integration snippets.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->publishConfig($dryRun);
        $this->publishMediaMigration($dryRun);
        $this->publishMailLogMigrations($dryRun);
        $this->maybeMigrate($dryRun);
        $this->writeEnvKeys($dryRun);
        $this->emitSnippets();
        $this->summary($dryRun);

        return self::SUCCESS;
    }

    private function publishConfig(bool $dryRun): void
    {
        if ($dryRun) {
            $this->line('Would publish: config/mail-log.php (tag: mail-log-config).');

            return;
        }

        $this->call('vendor:publish', ['--tag' => 'mail-log-config', '--force' => false]);
    }

    private function publishMediaMigration(bool $dryRun): void
    {
        if (Schema::hasTable('media')) {
            $this->line('Spatie media table already present — no migration publish needed.');

            return;
        }

        if ($this->mediaMigrationPublished()) {
            $this->line('Spatie media migration already published — skipping.');

            return;
        }

        if ($dryRun) {
            $this->line('Would publish: Spatie laravel-medialibrary migration (provider: MediaLibraryServiceProvider).');

            return;
        }

        $this->call('vendor:publish', [
            '--tag' => 'medialibrary-migrations',
            '--force' => false,
        ]);
    }

    private function publishMailLogMigrations(bool $dryRun): void
    {
        if ($this->mailLogMigrationsPublished()) {
            $this->line('Mail Log migrations already published — skipping.');

            return;
        }

        if ($dryRun) {
            $this->line('Would publish: database/migrations/*_create_mail_log_groups_table.php + *_create_mail_logs_table.php (tag: mail-log-migrations).');

            return;
        }

        $this->call('vendor:publish', ['--tag' => 'mail-log-migrations', '--force' => false]);
    }

    private function maybeMigrate(bool $dryRun): void
    {
        if ($dryRun) {
            $this->line('Would prompt: run `php artisan migrate` now.');

            return;
        }

        if (! $this->confirm('Run `php artisan migrate` now?', default: true)) {
            $this->line('Skipped migrations — run `php artisan migrate` manually when ready.');

            return;
        }

        $this->call('migrate', ['--force' => true]);
    }

    private function writeEnvKeys(bool $dryRun): void
    {
        if ($dryRun) {
            $this->line('Would set in .env (if absent): '.implode(', ', array_keys(self::ENV_DEFAULTS)));

            return;
        }

        $env = new EnvWriter(base_path('.env'));
        $written = [];

        foreach (self::ENV_DEFAULTS as $key => $value) {
            if ($env->setIfAbsent($key, $value)) {
                $written[] = $key;
            }
        }

        $example = new EnvWriter(base_path('.env.example'));

        if ($example->exists()) {
            foreach (self::ENV_DEFAULTS as $key => $value) {
                $example->setIfAbsent($key, $value);
            }
        }

        if ($written === []) {
            $this->line('Mail Log env keys already present in .env — left as-is.');

            return;
        }

        $this->info('Wrote '.count($written).' env key(s): '.implode(', ', $written));
    }

    private function emitSnippets(): void
    {
        $this->writeRawHeader('Add to AppServiceProvider::boot() — registers the dashboard auth gate + a stable morph alias:');
        $this->writeRawSnippet(<<<'PHP'
            use Phattarachai\MailLogLaravel\MailLog;
            use Phattarachai\MailLogLaravel\Models\MailLogGroup;

            MailLogGroup::registerMorphMap();
            MailLog::auth(function ($request) {
                return $request->user()?->isAdmin() ?? false;
            });
            PHP);

        $this->writeRawHeader('Opt a Mailable into class+model grouping (the headline behavior):');
        $this->writeRawSnippet(<<<'PHP'
            use Illuminate\Mail\Mailable;
            use Illuminate\Mail\Mailables\Headers;
            use Phattarachai\MailLogLaravel\Concerns\HasMailLog;

            class OrderShippedMail extends Mailable
            {
                use HasMailLog;

                public function __construct(public Order $order) {}

                protected function mailLogModel(): ?\Illuminate\Database\Eloquent\Model
                {
                    return $this->order;
                }

                // Default fingerprint mode is ['class', 'model']. Override to fold
                // hints in: return ['class', 'model', 'hints']; then implement
                // mailLogFingerprintHints(): array on this Mailable.

                public function headers(): Headers
                {
                    return $this->withMailLog(new Headers());
                }
            }
            PHP);

        $this->writeRawHeader('Schedule pruning of old groups and settling of stale Pending sends:');
        $this->writeRawSnippet(<<<'PHP'
            // routes/console.php
            use Illuminate\Support\Facades\Schedule;
            use Phattarachai\MailLogLaravel\Models\MailLogGroup;

            Schedule::command('model:prune', ['--model' => [MailLogGroup::class]])->daily();
            Schedule::command('mail-log:settle-stale')->everyTenMinutes();
            PHP);
    }

    private function summary(bool $dryRun): void
    {
        $url = rtrim((string) config('app.url', 'http://localhost'), '/').'/'.ltrim((string) config('mail-log.ui.path', 'mail-log'), '/');

        $this->writeRawLine('');

        if ($dryRun) {
            $this->info('--dry-run: no files were modified.');

            return;
        }

        $this->info('Mail Log dashboard mounted at '.$url);
        $this->line('Default auth gate: APP_DEBUG=true only. Register MailLog::auth(...) above to expose it in production.');
    }

    private function mediaMigrationPublished(): bool
    {
        return $this->migrationDirectoryContains('create_media_table');
    }

    private function mailLogMigrationsPublished(): bool
    {
        return $this->migrationDirectoryContains('create_mail_log_groups_table');
    }

    private function migrationDirectoryContains(string $needle): bool
    {
        $path = database_path('migrations');

        if (! is_dir($path)) {
            return false;
        }

        return array_any((array) glob($path.'/*.php'), fn ($file) => is_string($file) && str_contains(basename($file), $needle));
    }

    private function writeRawHeader(string $message): void
    {
        $this->output->writeln('', OutputInterface::OUTPUT_RAW);
        $this->output->writeln($message, OutputInterface::OUTPUT_RAW);
    }

    private function writeRawSnippet(string $snippet): void
    {
        $this->output->writeln('', OutputInterface::OUTPUT_RAW);

        foreach (explode("\n", $snippet) as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        $this->output->writeln('', OutputInterface::OUTPUT_RAW);
    }

    private function writeRawLine(string $line): void
    {
        $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
    }
}
