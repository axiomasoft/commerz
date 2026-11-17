<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Events;

use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

/** Dispatched after the change that left the order fulfilled and paid commits. */
#[Api(since: '0.1')]
final readonly class OrderCompleted
{
    public function __construct(public OrderId $order) {}
}
