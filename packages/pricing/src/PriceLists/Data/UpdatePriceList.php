<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Data;

use Commerz\Attributes\Api;
use Commerz\Pricing\PriceLists\Enums\TaxInclusion;

/** Renames a price list or changes its priority or tax inclusion; a field left null stays. Code and currency never change. */
#[Api(since: '0.1')]
final readonly class UpdatePriceList
{
    public function __construct(
        public PriceListId $priceList,
        public ?string $name = null,
        public ?int $priority = null,
        public ?TaxInclusion $taxInclusion = null,
    ) {
        if ($name !== null) {
            CreatePriceList::assertName($name);
        }

        if ($priority !== null) {
            CreatePriceList::assertPriority($priority);
        }
    }
}
