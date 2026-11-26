<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Facades;

use Commerz\Subscriptions\Billing\Contracts\BillingSchedule;
use Commerz\Subscriptions\Lifecycle\Contracts\SubscriptionContracts;
use Commerz\Subscriptions\Renewals\Contracts\RenewalOrders;
use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Api;

/**
 * @method static SubscriptionContracts contracts()
 * @method static BillingSchedule billing()
 * @method static RenewalOrders renewals()
 *
 * @see \Commerz\Subscriptions\Subscriptions
 */
#[Api(since: '0.1')]
final class Subscriptions extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Commerz\Subscriptions\Subscriptions::class;
    }
}
