<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Events;

use Commerz\Attributes\Api;
use Commerz\Money\Money;
use Commerz\Sales\Orders\Data\OrderId;

/**
 * Dispatched after a change that left the order holding more money than it is due commits: a cancellation of a paid
 * order or of some of its lines, or a capture after the cancellation. Nothing is refunded automatically.
 */
#[Api(since: '0.1')]
final readonly class PaymentRequiresRefund
{
    /** @param  Money  $amount  by how much the money held over the amount due grew with this change */
    public function __construct(public OrderId $order, public Money $amount) {}
}
