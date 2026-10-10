<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Fixtures;

use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;

/** With the code `vat` presented, adds an excluded tax of 1.00 to every line and 2.00 to delivery. */
final readonly class VatStage implements QuoteStage
{
    public function key(): string
    {
        return 'test.vat';
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Tax;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        if (! in_array('vat', $state->input->codes, true)) {
            return $state;
        }

        foreach ($state->input->lines as $line) {
            $state = $state->withTaxLine(new TaxLine(AdjustmentTarget::Line, $line->lineId, 'vat', Rate::bp(2000), Money::of('1.00', $state->currency()), false));
        }

        if ($state->deliveryNet() !== null) {
            $state = $state->withTaxLine(new TaxLine(AdjustmentTarget::Delivery, null, 'vat', Rate::bp(2000), Money::of('2.00', $state->currency()), false));
        }

        return $state;
    }
}
