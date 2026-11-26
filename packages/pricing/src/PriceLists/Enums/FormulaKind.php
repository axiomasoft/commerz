<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum FormulaKind: string
{
    case MarginPercent = 'margin_percent';
    case FixedDelta = 'fixed_delta';
    case FxRateWithSpread = 'fx_rate_with_spread';
}
