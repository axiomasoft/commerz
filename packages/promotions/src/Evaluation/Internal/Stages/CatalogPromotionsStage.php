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
use Packman\Attributes\Internal;

/**
 * Runs the catalog promotions (line discounts on prices) right after the base prices. It starts the quote's promotion session: the promotions, activations and counters the cart and delivery stages reuse, and the rejections of presented codes.
 * The engine is resolved per run, so a binding changed after the registry froze (a test fake) is still used.
 */
#[Internal]
final readonly class CatalogPromotionsStage implements QuoteStage
{
    public function __construct(private Container $container) {}

    public function key(): string
    {
        return PromotionStage::Catalog->stageKey();
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::CatalogPromotions;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        return $this->container->make(PromotionEngine::class)->run(PromotionStage::Catalog, $state);
    }
}
