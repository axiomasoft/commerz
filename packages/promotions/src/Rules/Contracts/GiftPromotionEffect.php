<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Contracts;

use Commerz\Promotions\Rules\Data\EffectInput;
use Commerz\Promotions\Rules\Data\GiftEffectResult;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Optional extension of {@see PromotionEffect} for effects that add free gift lines or emit selectable gift offers
 * onto the quote state.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface GiftPromotionEffect extends PromotionEffect
{
    /**
     * Pure calculation returning zero-priced gift lines and/or selectable gift offers.
     * The engine validates every returned line and offer (PRM-05) before applying them atomically.
     */
    public function applyGifts(EffectInput $input, ?string $couponCode = null): GiftEffectResult;
}
