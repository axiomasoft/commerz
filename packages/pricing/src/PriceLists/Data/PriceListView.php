<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Data;

use Commerz\Money\Currency;
use Commerz\Pricing\PriceLists\Enums\PriceListStatus;
use Commerz\Pricing\PriceLists\Enums\TaxInclusion;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PriceListView
{
    /** @param  list<ChannelId>  $channels sorted by id */
    public function __construct(
        public PriceListId $id,
        public string $code,
        public string $name,
        public Currency $currency,
        public int $priority,
        public TaxInclusion $taxInclusion,
        public PriceListStatus $status,
        public array $channels,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?PriceListId $parentPriceListId = null,
        public ?PriceListFormula $formula = null,
        public ?RoundingRule $roundingRule = null,
    ) {}
}
