<?php

declare(strict_types=1);

namespace Phattarachai\MailLogLaravel\Console;

use Illuminate\Console\Command;
use Phattarachai\MailLogLaravel\Exceptions\CannotResend;
use Phattarachai\MailLogLaravel\Models\MailLog;
use Phattarachai\MailLogLaravel\Support\MailResender;

class ResendCommand extends Command
{
    protected $signature = 'mail-log:resend
        {event : The id of the send (mail_logs.id) to resend}
        {--to=* : Send to these addresses instead of the original recipients}';

    protected $description = 'Resend a logged send of a Mailable that opts in with mailLogResendable()';

    public function handle(MailResender $resender): int
    {
        $event = MailLog::query()->find($this->argument('event'));

        if (! $event instanceof MailLog) {
            $this->components->error('No send with id '.$this->argument('event').'.');

            return self::FAILURE;
        }

        /** @var list<string> $to */
        $to = array_values(array_filter((array) $this->option('to')));

        try {
            $resender->resend($event, $to === [] ? null : $to);
        } catch (CannotResend $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Resent send #%d to %s.', $event->id, $to === [] ? 'the original recipients' : implode(', ', $to)));

        return self::SUCCESS;
    }
}
