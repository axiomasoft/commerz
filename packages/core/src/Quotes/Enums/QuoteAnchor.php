<?php

declare(strict_types=1);

namespace Commerz\Quotes\Enums;

use Packman\Attributes\Api;

/** Fixed points of the quote pipeline, in run order (the order of the cases). */
#[Api(since: '0.1')]
enum QuoteAnchor: string
{
    case CartTransform = 'cart_transform';
    case Base = 'base';
    case CatalogPromotions = 'promotions.catalog';
    case CartPromotions = 'promotions.cart';
    case Delivery = 'delivery';
    case DeliveryPromotions = 'promotions.delivery';
    case Tax = 'tax';
    case Totals = 'totals';

    /** @return int<0, max> */
    public function order(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
