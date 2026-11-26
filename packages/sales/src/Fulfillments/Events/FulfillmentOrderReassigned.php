<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Events;

use Commerz\Sales\Fulfillments\Data\FulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrderId;
use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

/** Dispatched after a {@see FulfillmentOrder} is reassigned to another warehouse. */
#[Api(since: '0.1')]
final readonly class FulfillmentOrderReassigned
{
    public function __construct(
        public FulfillmentOrderId $fulfillmentOrder,
        public OrderId $order,
        public string $previousLocationCode,
        public string $locationCode,
    ) {}
}
