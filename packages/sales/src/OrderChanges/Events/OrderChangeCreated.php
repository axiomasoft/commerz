<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Events;

use Commerz\Sales\OrderChanges\Data\OrderChangeId;
use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

/** Dispatched after commit when a draft order change is created. */
#[Api(since: '0.1')]
final readonly class OrderChangeCreated
{
    public function __construct(
        public OrderChangeId $change,
        public OrderId $order,
        public int $baseOrderVersion,
    ) {}
}
