<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\Prices\Enums\StagedPriceStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class StagedPriceView
{
    public function __construct(
        public StagedPriceId $id,
        public ?string $batchCode,
        public PriceListId $priceList,
        public SellableRef $sellable,
        public ?string $customerGroup,
        public int $minQuantity,
        public Money $amount,
        public ?Money $compareAt,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
        public StagedPriceStatus $status,
        public ?PriceId $publishedPriceId,
        public ?DateTimeImmutable $publishedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
