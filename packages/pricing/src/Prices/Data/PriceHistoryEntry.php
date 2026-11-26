<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use Commerz\Pricing\Prices\Enums\PriceHistoryEvent;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** One immutable entry in the append-only `cmz_price_history` journal. */
#[Api(since: '0.1')]
final readonly class PriceHistoryEntry
{
    public function __construct(
        public int $id,
        public PriceId $price,
        public PriceListId $priceList,
        public SellableRef $sellable,
        public ?string $customerGroup,
        public int $minQuantity,
        public Money $amount,
        public ?Money $compareAt,
        public PriceHistoryEvent $event,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
        public DateTimeImmutable $recordedAt,
    ) {}
}
