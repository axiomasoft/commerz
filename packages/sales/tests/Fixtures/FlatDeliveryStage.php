<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Fixtures;

use Commerz\Money\Money;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;

/** Prices a selected method `flat:{price}` at that price, standing in for the shipping package. */
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

        if ($selection === null || ! str_starts_with($selection->methodCode, 'flat:')) {
            return $state;
        }

        $price = Money::of(substr($selection->methodCode, strlen('flat:')), $state->currency());

        return $state->withDelivery(new DeliveryCharge($selection->methodCode, 'Flat', 'test', $price));
    }
}
