<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Enums;

use Commerz\Attributes\Api;
use Commerz\Quotes\Enums\QuoteAnchor;

/** Where in the quote a promotion works. Fixed when the promotion is created. */
#[Api(since: '0.1')]
enum PromotionStage: string
{
    case Catalog = 'catalog';
    case Cart = 'cart';
    case Delivery = 'delivery';

    public function anchor(): QuoteAnchor
    {
        return match ($this) {
            self::Catalog => QuoteAnchor::CatalogPromotions,
            self::Cart => QuoteAnchor::CartPromotions,
            self::Delivery => QuoteAnchor::DeliveryPromotions,
        };
    }

    /** Key of the quote stage that runs the promotions of this stage: `promotions.catalog`, `promotions.cart`, `promotions.delivery`. */
    public function stageKey(): string
    {
        return $this->anchor()->value;
    }
}
