<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests\Fixtures;

use Commerz\Money\Money;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;

/** Prices the selected delivery from its method code: `flat:300.00` costs 300.00 in the quote's currency. */
final readonly class FlatDeliveryStage implements QuoteStage
{
    public function key(): string
    {
        return 'test.flat_delivery';
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Delivery;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        $selection = $state->input->delivery;

        if ($selection === null) {
            return $state;
        }

        [, $price] = explode(':', $selection->methodCode, 2);

        return $state->withDelivery(new DeliveryCharge($selection->methodCode, 'Flat', 'test', Money::of($price, $state->currency())));
    }
}
