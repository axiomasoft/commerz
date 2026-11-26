<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Events;

use Commerz\Sales\Exchanges\Data\ClaimId;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class OrderClaimFulfilled
{
    public function __construct(
        public ClaimId $claim,
        public OrderId $order,
        public FulfillmentId $fulfillment,
    ) {}
}
