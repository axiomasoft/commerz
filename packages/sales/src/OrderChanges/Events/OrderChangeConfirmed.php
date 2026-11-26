<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Events;

use Commerz\Money\Money;
use Commerz\Sales\OrderChanges\Data\OrderChangeId;
use Commerz\Sales\OrderChanges\Enums\CompensationStrategy;
use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

/** Dispatched after commit when an order change is confirmed and the order version is bumped. */
#[Api(since: '0.1')]
final readonly class OrderChangeConfirmed
{
    public function __construct(
        public OrderChangeId $change,
        public OrderId $order,
        public int $fromVersion,
        public int $toVersion,
        public Money $amountDelta,
        public Money $taxDelta,
        public CompensationStrategy $compensationStrategy,
    ) {}
}
