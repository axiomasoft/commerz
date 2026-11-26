<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Events;

use Commerz\Catalog\Products\Data\ProductId;
use Packman\Attributes\Api;

/** Dispatched once after a `Products::upsertMany()` transaction commits. */
#[Api(since: '0.1')]
final readonly class ProductsUpserted
{
    /**
     * @param  list<ProductId>  $createdIds
     * @param  list<ProductId>  $updatedIds
     */
    public function __construct(
        public string $batchId,
        public array $createdIds,
        public array $updatedIds,
        public int $count,
    ) {}
}
