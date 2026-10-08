<?php

declare(strict_types=1);

namespace Commerz\Promotions\Internal\Pricing;

use Commerz\Pricing\Contracts\PricingStage;
use Commerz\Pricing\Data\PricingState;
use Commerz\Pricing\Enums\PricingAnchor;
use Commerz\Pricing\Enums\StagePosition;
use Commerz\Promotions\Enums\PromotionStage;
use Commerz\Promotions\Internal\CandidateSource;
use Commerz\Promotions\Internal\PromotionEngine;
use Override;

/**
 * Владелец якоря cart_promotions. Регистрируется пакетом commerz-promotions в своём manifest.
 * Чтения (кандидаты, активаторы) — да, записи — нет: лимиты расходуются только при размещении заказа.
 */
final readonly class CartPromotionsStage implements PricingStage
{
    public function __construct(private CandidateSource $candidates, private PromotionEngine $engine) {}

    #[Override]
    public function key(): string
    {
        return 'commerz.cart_promotions';
    }

    #[Override]
    public function anchor(): PricingAnchor
    {
        return PricingAnchor::CartPromotions;
    }

    #[Override]
    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    #[Override]
    public function apply(PricingState $state): PricingState
    {
        $loaded = $this->candidates->forStage(PromotionStage::Cart, $state->context, $state->enteredCodes);

        // отказы активаторов (неизвестный/просроченный код) — часть объяснения, а не исключение
        $state = $state->with($state->lines, [], $loaded->rejections);

        return $this->engine->run(PromotionStage::Cart, $state, $loaded->candidates);
    }
}
