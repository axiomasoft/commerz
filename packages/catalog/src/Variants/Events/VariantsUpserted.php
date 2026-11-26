<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Events;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Variants\Data\VariantId;
use Packman\Attributes\Api;

/** Dispatched once after a `Variants::upsertMany()` transaction commits. */
#[Api(since: '0.1')]
final readonly class VariantsUpserted
{
    /**
     * @param  list<ProductId>  $productIds  affected products
     * @param  list<VariantId>  $createdIds
     * @param  list<VariantId>  $updatedIds
     */
    public function __construct(
        public string $batchId,
        public array $productIds,
        public array $createdIds,
        public array $updatedIds,
        public int $count,
    ) {}
}
