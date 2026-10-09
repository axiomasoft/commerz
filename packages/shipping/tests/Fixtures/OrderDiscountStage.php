<?php

declare(strict_types=1);

namespace Commerz\Shipping\Tests\Fixtures;

use Commerz\Money\Money;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;

/** After the cart promotions, adds an order-level discount for each presented code `order:600.00`. */
final readonly class OrderDiscountStage implements QuoteStage
{
    public function key(): string
    {
        return 'test.order_discount';
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::CartPromotions;
    }

    public function position(): StagePosition
    {
        return StagePosition::After;
    }

    public function apply(QuoteState $state): QuoteState
    {
        foreach ($state->input->codes as $code) {
            [$kind, $amount] = explode(':', $code, 2) + [1 => '0'];

            if ($kind === 'order') {
                $state = $state->withAdjustment(Adjustment::order(Money::of($amount, $state->currency())->negate(), $this->key(), $code));
            }
        }

        return $state;
    }
}
