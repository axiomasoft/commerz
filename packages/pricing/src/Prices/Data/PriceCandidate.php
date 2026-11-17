<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Money\Money;
use Commerz\Pricing\Prices\Enums\CandidateVerdict;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** A price of the sellable in an active price list of the channel and currency, with its place in the decision. */
#[Api(since: '0.1')]
final readonly class PriceCandidate
{
    public function __construct(
        public PriceId $price,
        public string $priceList,
        public int $priority,
        public ?string $customerGroup,
        public int $minQuantity,
        public Money $amount,
        public ?Money $compareAt,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
        public CandidateVerdict $verdict,
    ) {}
}
