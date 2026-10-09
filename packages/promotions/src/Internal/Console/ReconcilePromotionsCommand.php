<?php

declare(strict_types=1);

namespace Commerz\Promotions\Internal\Console;

use Commerz\Promotions\Usage\Internal\Discrepancy;
use Commerz\Promotions\Usage\Internal\UsageReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Compares the usage counters with the usage log and lists the rules no plugin can run; exit 1 when anything is off.
 * Read-only: a counter is rebuilt by changing the limit (UpdatePromotion, SetCouponLimit), which recomputes it.
 */
#[Description('Reconcile Commerz promotion and coupon usage counters with the usage log.')]
#[Signature('commerz:promotions:reconcile')]
final class ReconcilePromotionsCommand extends Command
{
    public function handle(UsageReconciler $reconciler): int
    {
        $discrepancies = $reconciler->check();

        if ($discrepancies === []) {
            $this->components->info('Promotions are consistent.');

            return self::SUCCESS;
        }

        $this->table(['kind', 'ref', 'expected', 'actual'], array_map(
            static fn (Discrepancy $d): array => [$d->kind, $d->ref, $d->expected, $d->actual],
            $discrepancies,
        ));
        $this->components->error(count($discrepancies).' discrepancies.');

        return self::FAILURE;
    }
}
