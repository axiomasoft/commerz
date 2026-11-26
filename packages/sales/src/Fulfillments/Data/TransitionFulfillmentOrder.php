<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Data;

use Commerz\Identity\ActorRef;
use Commerz\Sales\Fulfillments\Enums\FulfillmentOrderStatus;
use Packman\Attributes\Api;

/** Transitions a {@see FulfillmentOrder} to a new lifecycle state, optionally linking a physical {@see FulfillmentId}. */
#[Api(since: '0.1')]
final readonly class TransitionFulfillmentOrder
{
    public function __construct(
        public FulfillmentOrderId $fulfillmentOrder,
        public FulfillmentOrderStatus $status,
        public ?FulfillmentId $fulfillment = null,
        public ?ActorRef $actor = null,
    ) {}
}
