<?php

declare(strict_types=1);

namespace Commerz\Catalog\Relations\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Relations\Enums\ProductRelationType;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Adds or updates a typed relation from sourceProduct to targetProduct. */
#[Api(since: '0.1')]
final readonly class AddProductRelation
{
    public function __construct(
        public ProductId $sourceProduct,
        public ProductId $targetProduct,
        public ProductRelationType $type,
        public int $position = 0,
    ) {
        if ($sourceProduct->equals($targetProduct)) {
            throw new InvalidCommand('targetProduct', 'a product cannot relate to itself');
        }

        if ($position < 0) {
            throw new InvalidCommand('position', 'must not be negative');
        }
    }
}
