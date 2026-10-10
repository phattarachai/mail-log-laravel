<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\MailLogLaravel\Enums\MailLogStatus;
use Phattarachai\MailLogLaravel\Models\MailLog;

/**
 * Backstop for sends that never confirm and that no in-process signal
 * settled: a worker killed mid-send, a timeout that will retry on another
 * worker, a crashed request. Schedule it every few minutes.
 */
class SettleStaleCommand extends Command
{
    protected $signature = 'mail-log:settle-stale
        {--minutes= : Settle Pending sends older than this (default: mail-log.pending_timeout_minutes)}';

    protected $description = 'Mark Mail Log sends still Pending past the timeout as Failed';

    public function handle(): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('mail-log.pending_timeout_minutes', 15));

        if ($minutes < 1) {
            $this->components->error('The timeout must be at least 1 minute.');

            return self::FAILURE;
        }

        $message = sprintf(
            'No delivery confirmation within %d minutes. The send was settled as failed by mail-log:settle-stale; the process likely died or the transport threw before MessageSent.',
            $minutes,
        );

        $settled = 0;

        MailLog::query()
            ->where('status', MailLogStatus::Pending)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->with('group')
            ->lazyById()
            ->each(function (MailLog $row) use ($message, &$settled): void {
                $row->markFailed($message);
                $settled++;
            });

        $this->components->info(sprintf('Settled %d stale pending %s.', $settled, str('send')->plural($settled)));

        return self::SUCCESS;
    }
}
