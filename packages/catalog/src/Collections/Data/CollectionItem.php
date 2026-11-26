<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Positioned product entry in a manual collection. */
#[Api(since: '0.1')]
final readonly class CollectionItem
{
    public function __construct(
        public ProductId $product,
        public int $position = 0,
    ) {
        if ($position < 0) {
            throw new InvalidCommand('position', 'must not be negative');
        }
    }
}
