<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Promotions\Rules\Contracts\GiftPromotionEffect;
use Commerz\Quotes\Data\QuoteLine;
use Commerz\Quotes\Data\SelectableGiftOffer;
use Packman\Attributes\Api;

/**
 * Non-monetary gift outcomes produced by a {@see GiftPromotionEffect}:
 * auto-added zero-priced gift lines and/or selectable gift offers.
 */
#[Api(since: '0.1')]
final readonly class GiftEffectResult
{
    /**
     * @param  list<QuoteLine>  $giftLines
     * @param  list<SelectableGiftOffer>  $giftOffers
     */
    public function __construct(
        public array $giftLines = [],
        public array $giftOffers = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->giftLines === [] && $this->giftOffers === [];
    }
}
