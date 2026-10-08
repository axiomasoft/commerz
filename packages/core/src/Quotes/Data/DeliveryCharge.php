<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;

/** The priced delivery method, before delivery adjustments and taxes. */
#[Api(since: '0.1')]
final readonly class DeliveryCharge
{
    public function __construct(
        public string $methodCode,
        public string $name,
        public string $carrier,
        public Money $price,
        public bool $taxIncluded = false,
    ) {
        if ($price->isNegative()) {
            throw new InvalidCommand('price', 'must not be negative', 'quotes.invalid_delivery');
        }
    }
}
