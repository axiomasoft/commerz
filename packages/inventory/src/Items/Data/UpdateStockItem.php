<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Data;

use Commerz\Inventory\Items\Enums\StockPolicy;
use Packman\Attributes\Api;

/** Renames an item or changes its policy; a field left null stays. The SKU and the unit never change. */
#[Api(since: '0.1')]
final readonly class UpdateStockItem
{
    public function __construct(
        public StockItemId $item,
        public ?string $name = null,
        public ?StockPolicy $policy = null,
        public ?string $hsCode = null,
        public ?string $originCountryCode = null,
        public ?string $material = null,
        public ?string $customsDescription = null,
    ) {
        CreateStockItem::assertName($name);
        CreateStockItem::assertCustoms($hsCode, $originCountryCode, $material, $customsDescription);
    }
}
