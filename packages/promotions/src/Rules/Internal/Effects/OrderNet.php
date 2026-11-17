<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal\Effects;

use Commerz\Money\Money;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Packman\Attributes\Internal;

/** What an order-level discount is taken from: the items' net plus the (negative) order adjustments already in the state. */
#[Internal]
final class OrderNet
{
    public static function of(QuoteState $state): Money
    {
        $net = $state->itemsNet();

        foreach ($state->adjustments as $adjustment) {
            if ($adjustment->target === AdjustmentTarget::Order) {
                $net = $net->plus($adjustment->amount);
            }
        }

        return $net;
    }
}
