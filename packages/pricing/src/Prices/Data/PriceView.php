<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use DateTimeImmutable;

#[Api(since: '0.1')]
final readonly class PriceView
{
    public function __construct(
        public PriceId $id,
        public PriceListId $priceList,
        public SellableRef $sellable,
        public ?string $customerGroup,
        public int $minQuantity,
        public Money $amount,
        public ?Money $compareAt,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
