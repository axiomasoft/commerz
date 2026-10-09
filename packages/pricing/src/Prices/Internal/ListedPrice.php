<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Internal;

use DateTimeImmutable;

/** A stored price with the facts of its price list that the decision needs; the amounts are in the context currency. */
final readonly class ListedPrice
{
    public function __construct(
        public string $id,
        public string $sellable,
        public string $priceList,
        public int $priority,
        public ?bool $taxIncluded,
        public ?string $customerGroup,
        public int $minQuantity,
        public int $amountMinor,
        public ?int $compareAtMinor,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
    ) {}
}
