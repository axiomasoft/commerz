<?php

declare(strict_types=1);

namespace Commerz\Sellable;

use Packman\Attributes\Api;

/**
 * How a selling plan schedules customer payment collection:
 * - `Recurring`: automatic recurring billing cycles (subscriptions)
 * - `Deposit`: partial upfront deposit now, remaining balance later (pre-orders, layaway, made-to-order)
 * - `Deferred`: zero upfront charge at checkout, full balance collected later (try-before-you-buy)
 */
#[Api(since: '0.1')]
enum BillingPolicy: string
{
    case Recurring = 'recurring';
    case Deposit = 'deposit';
    case Deferred = 'deferred';
}
