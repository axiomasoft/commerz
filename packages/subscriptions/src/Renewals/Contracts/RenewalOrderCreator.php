<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Renewals\Contracts;

use Commerz\Subscriptions\Billing\Contracts\BillingSchedule;
use Commerz\Subscriptions\Renewals\Data\PlaceRenewalOrder;
use Commerz\Subscriptions\Renewals\Data\RenewalOrderResult;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * SPI invoked by {@see BillingSchedule} to charge the customer's saved
 * payment method and create the renewal order for a due recurring cycle.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface RenewalOrderCreator
{
    public function placeRenewalOrder(PlaceRenewalOrder $command): RenewalOrderResult;
}
