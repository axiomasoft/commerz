<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Data;

use Commerz\Inventory\Allocation\Contracts\OrderRouter;
use Commerz\Ports\Delivery\Data\OrderRoutingContext;
use Packman\Attributes\Api;

/** Input to {@see OrderRouter}: stock allocation demand plus routing context. */
#[Api(since: '0.1')]
final readonly class OrderRoutingRequest
{
    public function __construct(
        public AllocationRequest $allocation,
        public OrderRoutingContext $context = new OrderRoutingContext,
    ) {}
}
