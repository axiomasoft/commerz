<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Identity\SellableRef;
use Commerz\Identity\SellerRef;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CartLineView
{
    public function __construct(
        public string $id,
        public SellableRef $sellable,
        public int $quantity,
        public ?SellerRef $sellerRef = null,
    ) {}
}
