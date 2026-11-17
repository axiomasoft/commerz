<?php

declare(strict_types=1);

namespace Commerz\Sales\Internal\Console;

use Commerz\Sales\Orders\Contracts\Orders;
use Commerz\Sales\Orders\Data\ExpireOrders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Packman\Attributes\Internal;

/** One expiry pass over the unpaid orders past their payment deadline (D32); schedule it, e.g. every minute. */
#[Internal]
#[Description('Expire unpaid Commerz orders whose payment deadline has passed.')]
#[Signature('commerz:sales:expire-orders {--limit= : Orders to expire in this pass, 1..1000 (default commerz-sales.orders.expire_batch)}')]
final class ExpireOrdersCommand extends Command
{
    public function handle(Orders $orders): int
    {
        $limit = $this->option('limit');

        if ($limit !== null && (! is_string($limit) || preg_match('/\A[1-9]\d{0,3}\z/', $limit) !== 1 || (int) $limit > 1000)) {
            $this->components->error('--limit must be an integer from 1 to 1000.');

            return self::FAILURE;
        }

        $report = $orders->expireDue(new ExpireOrders(limit: $limit === null ? null : (int) $limit));
        $this->components->info(sprintf('expired %d, skipped %d', count($report->expired), $report->skipped));

        return self::SUCCESS;
    }
}
