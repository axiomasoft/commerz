<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Data;

use Commerz\Inventory\Items\Data\StockItemId;
use Packman\Attributes\Api;

/** `inTransit = quantity − received − lost` once shipped; before the dispatch the units are still reserved at the source. */
#[Api(since: '0.1')]
final readonly class TransferLineView
{
    public function __construct(
        public StockItemId $item,
        public string $sku,
        public int $quantity,
        public int $received,
        public int $lost,
        public int $inTransit,
    ) {}
}
