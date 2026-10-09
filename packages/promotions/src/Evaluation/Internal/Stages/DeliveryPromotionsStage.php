<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal\Stages;

use Commerz\Promotions\Evaluation\Internal\PromotionEngine;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Illuminate\Contracts\Container\Container;

/**
 * Runs the delivery promotions on the priced delivery.
 * The engine is resolved per run, so a binding changed after the registry froze (a test fake) is still used.
 */
final readonly class DeliveryPromotionsStage implements QuoteStage
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return PromotionStage::Delivery->stageKey();
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::DeliveryPromotions;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        return $this->container->make(PromotionEngine::class)->run(PromotionStage::Delivery, $state);
    }
}
