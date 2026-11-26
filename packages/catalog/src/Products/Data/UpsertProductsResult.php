<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Data;

use Packman\Attributes\Api;

/** Outcome of `Products::upsertMany()` in input order, with batch counters. */
#[Api(since: '0.1')]
final readonly class UpsertProductsResult
{
    /**
     * @param  list<ProductView>  $products  upserted products in the order given
     * @param  list<ProductId>  $createdIds
     * @param  list<ProductId>  $updatedIds
     */
    public function __construct(
        public array $products,
        public array $createdIds,
        public array $updatedIds,
        public int $createdCount,
        public int $updatedCount,
        public int $indexedFactCount,
        public int $outboxEventCount,
    ) {}
}
