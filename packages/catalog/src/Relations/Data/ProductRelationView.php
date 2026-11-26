<?php

declare(strict_types=1);

namespace Commerz\Catalog\Relations\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Relations\Enums\ProductRelationType;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ProductRelationView
{
    public function __construct(
        public ProductRelationId $id,
        public ProductId $sourceProduct,
        public ProductId $targetProduct,
        public ProductRelationType $type,
        public int $position,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
