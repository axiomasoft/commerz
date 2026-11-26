<?php

declare(strict_types=1);

namespace Commerz\Catalog\Relations\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Relations\Enums\ProductRelationType;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class RemoveProductRelation
{
    public function __construct(
        public ProductId $sourceProduct,
        public ProductId $targetProduct,
        public ProductRelationType $type,
    ) {}
}
