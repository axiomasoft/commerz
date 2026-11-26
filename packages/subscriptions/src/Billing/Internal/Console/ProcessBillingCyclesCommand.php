<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Billing\Internal\Console;

use Commerz\Subscriptions\Billing\Contracts\BillingSchedule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Packman\Attributes\Internal;

/**
 * Processes due recurring subscription billing cycles and schedules retries or next cycles.
 */
#[Internal]
#[Description('Process due Commerz subscription billing cycles and create renewal orders.')]
#[Signature('commerz:subscriptions:bill {--limit= : Maximum due billing cycles to process}')]
final class ProcessBillingCyclesCommand extends Command
{
    public function handle(BillingSchedule $billing): int
    {
        $rawLimit = $this->option('limit');
        $limit = is_numeric($rawLimit) ? (int) $rawLimit : null;
        $report = $billing->processDue($limit);

        $this->info(sprintf(
            'Processed %d due cycle(s): %d billed, %d scheduled for retry, %d exhausted.',
            $report->processed,
            $report->billed,
            $report->retrying,
            $report->exhausted,
        ));

        return self::SUCCESS;
    }
}
