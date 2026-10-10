<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Events;

use Commerz\Attributes\Api;
use Commerz\Sales\Orders\Data\OrderId;

/** Dispatched after the placement commits. */
#[Api(since: '0.1')]
final readonly class OrderPlaced
{
    public function __construct(public OrderId $order, public string $number) {}
}
