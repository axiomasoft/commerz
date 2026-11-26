<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Packman\Attributes\Api;

/** Outcome of `Variants::upsertMany()` in input order, with batch counters. */
#[Api(since: '0.1')]
final readonly class UpsertVariantsResult
{
    /**
     * @param  list<VariantView>  $variants  upserted variants in the order given
     * @param  list<VariantId>  $createdIds
     * @param  list<VariantId>  $updatedIds
     */
    public function __construct(
        public array $variants,
        public array $createdIds,
        public array $updatedIds,
        public int $createdCount,
        public int $updatedCount,
        public int $indexedFactCount,
        public int $outboxEventCount,
    ) {}
}
