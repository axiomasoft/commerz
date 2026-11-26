<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Lifecycle\Data;

use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Packman\Attributes\Api;

/**
 * Persisted line item of a {@see SubscriptionContract}.
 */
#[Api(since: '0.1')]
final readonly class SubscriptionLine
{
    public function __construct(
        public string $lineId,
        public SellableSnapshot $sellable,
        public int $quantity,
        public Money $baseUnitPrice,
    ) {}
}
