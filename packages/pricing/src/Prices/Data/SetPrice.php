<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Pricing\Common\Data\PricingCodes;
use Commerz\Pricing\PriceLists\Data\PriceListId;
use DateTimeImmutable;

/**
 * The price of a sellable in a price list for a customer group from a quantity step, valid in `[validFrom, validUntil)`.
 * The same key and window update the amount; a different window of the same key must not overlap it (PRC-01).
 */
#[Api(since: '0.1')]
final readonly class SetPrice
{
    public function __construct(
        public PriceListId $priceList,
        public SellableRef $sellable,
        public Money $amount,
        public ?Money $compareAt = null,
        public int $minQuantity = 1,
        public ?string $customerGroup = null,
        public ?DateTimeImmutable $validFrom = null,
        public ?DateTimeImmutable $validUntil = null,
    ) {
        if ($minQuantity < 1) {
            throw new InvalidCommand('minQuantity', 'must be at least 1', 'pricing.invalid_quantity');
        }

        if ($amount->amountMinor < 0) {
            throw new InvalidCommand('amount', 'must not be negative', 'pricing.invalid_amount');
        }

        if ($compareAt instanceof Money) {
            if (! $compareAt->currency->equals($amount->currency)) {
                throw new InvalidCommand('compareAt', 'must be in the currency of the amount', 'pricing.currency_mismatch');
            }

            if ($compareAt->amountMinor <= $amount->amountMinor) {
                throw new InvalidCommand('compareAt', 'must be greater than the amount', 'pricing.invalid_compare_at');
            }
        }

        if ($customerGroup !== null) {
            PricingCodes::group('customerGroup', $customerGroup);
        }

        if ($validFrom instanceof DateTimeImmutable && $validUntil instanceof DateTimeImmutable && $validFrom >= $validUntil) {
            throw new InvalidCommand('validUntil', 'must be after validFrom', 'pricing.invalid_window');
        }
    }
}
