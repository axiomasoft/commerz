<?php

declare(strict_types=1);

namespace Commerz\Inventory\Internal\Console;

use Commerz\Inventory\Internal\Data\Discrepancy;
use Commerz\Inventory\Internal\Reconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Compares the ledger, reservation lines and transfers; exit 1 when they disagree. On-hand is never repaired here: correct it with a count. */
#[Description('Reconcile Commerz stock levels with the ledger, reservations and transfers.')]
#[Signature('commerz:inventory:reconcile {--repair-reserved : Rebuild `reserved` of the levels from their reservation lines}')]
final class ReconcileStockCommand extends Command
{
    public function handle(Reconciler $reconciler): int
    {
        $report = $reconciler->check();

        if ($report->clean()) {
            $this->components->info('Stock is consistent.');

            return self::SUCCESS;
        }

        $this->table(['kind', 'ref', 'expected', 'actual'], array_map(
            static fn (Discrepancy $d): array => [$d->kind, $d->ref, $d->expected, $d->actual],
            $report->discrepancies,
        ));

        if ($this->option('repair-reserved') === true) {
            $repaired = $reconciler->repairReserved();
            $this->components->info("Repaired reserved stock on {$repaired} levels.");
            $report = $reconciler->check();

            if ($report->clean()) {
                $this->components->info('Stock is consistent.');

                return self::SUCCESS;
            }

            $this->components->warn('Remaining discrepancies:');
            $this->table(['kind', 'ref', 'expected', 'actual'], array_map(
                static fn (Discrepancy $d): array => [$d->kind, $d->ref, $d->expected, $d->actual],
                $report->discrepancies,
            ));
        }

        $this->components->error(count($report->discrepancies).' discrepancies.');

        return self::FAILURE;
    }
}
