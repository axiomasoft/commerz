<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Events;

use Commerz\Attributes\Api;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Enums\CancelReason;

/** Dispatched after the cancellation of the order commits. */
#[Api(since: '0.1')]
final readonly class OrderCancelled
{
    public function __construct(public OrderId $order, public CancelReason $reason) {}
}
