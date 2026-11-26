<?php

declare(strict_types=1);

namespace Commerz\Sellable;

use Packman\Attributes\Api;

/**
 * Event or schedule point at which the remaining unpaid balance of a deposit or deferred selling plan becomes due.
 */
#[Api(since: '0.1')]
enum BalanceDueTrigger: string
{
    case BeforeShipment = 'before_shipment';
    case OnFulfillment = 'on_fulfillment';
    case AfterCheckoutDays = 'after_checkout_days';
    case FixedDate = 'fixed_date';
}
