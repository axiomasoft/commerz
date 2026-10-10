<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Fixtures;

use Commerz\Money\Money;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;

/** After the cart promotions, takes 10.00 off the order for each presented code `promo:{promotionId}`, as a promotion would. */
final readonly class PromotionAdjustmentStage implements QuoteStage
{
    public function key(): string
    {
        return 'test.promotion_adjustment';
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
            if (str_starts_with($code, 'promo:')) {
                $state = $state->withAdjustment(Adjustment::order(
                    Money::of('10.00', $state->currency())->negate(),
                    $this->key(),
                    'Promotion −10',
                    substr($code, strlen('promo:')),
                    $code,
                ));
            }
        }

        return $state;
    }
}
