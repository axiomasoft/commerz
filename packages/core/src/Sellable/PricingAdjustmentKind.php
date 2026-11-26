<?php

declare(strict_types=1);

namespace Commerz\Sellable;

use Packman\Attributes\Api;

/**
 * How a selling plan adjusts a sellable's resolved unit price.
 */
#[Api(since: '0.1')]
enum PricingAdjustmentKind: string
{
    case None = 'none';
    case PercentageOff = 'percentage_off';
    case FixedAmountOff = 'fixed_amount_off';
    case FixedPrice = 'fixed_price';
}
