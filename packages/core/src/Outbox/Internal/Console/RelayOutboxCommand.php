<?php

declare(strict_types=1);

namespace Commerz\Outbox\Internal\Console;

use Commerz\Outbox\Internal\OutboxRelay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Packman\Attributes\Internal;

#[Description('Deliver recorded Commerz integration events to listeners and outbox consumers.')]
#[Internal]
#[Signature('commerz:outbox:relay
        {--batch=100 : Messages claimed per round (1..1000)}
        {--once : Deliver what is ready now, then exit}')]
final class RelayOutboxCommand extends Command
{
    public function handle(OutboxRelay $relay): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);

        if (! is_int($batch) || $batch < 1 || $batch > 1000) {
            $this->components->error('--batch must be an integer from 1 to 1000.');

            return self::INVALID;
        }

        $stop = false;

        if ($this->option('once') !== true && extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], static function () use (&$stop): void {
                $stop = true;
            });
        }

        $delivered = 0;
        $failed = 0;

        do {
            $report = $relay->relay($batch);
            $delivered += $report->delivered;
            $failed += $report->failed;

            if ($report->claimed < $batch) {
                if ($this->option('once') === true) {
                    break;
                }

                Sleep::for(1)->second();
            }
        } while (! $stop);

        $this->components->info("Outbox: {$delivered} delivered, {$failed} failed.");

        return self::SUCCESS;
    }
}
