<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\SellableRef;

#[Api(since: '0.1')]
final readonly class StockLinkView
{
    public function __construct(
        public string $id,
        public SellableRef $sellable,
        public StockItemId $item,
        public string $sku,
        public int $quantityPerUnit,
    ) {}
}
