<?php

declare(strict_types=1);

namespace Commerz\Catalog\Relations\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Relations\Enums\ProductRelationType;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ListProductRelations
{
    public function __construct(
        public ProductId $sourceProduct,
        public ?ProductRelationType $type = null,
    ) {}
}
