<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Contracts;

use Commerz\Inventory\Allocation\Data\OrderRoutingRequest;
use Commerz\Inventory\Allocation\Data\OrderRoutingResult;
use Commerz\Ports\Delivery\Contracts\OrderRoutingRule;
use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Packman\Attributes\Api;

/**
 * Routes an order's stock needs across candidate locations via a pipeline of {@see OrderRoutingRule}s
 * while enforcing {@see FulfillmentConstraints}.
 */
#[Api(since: '0.1')]
interface OrderRouter
{
    public function route(OrderRoutingRequest $request): OrderRoutingResult;
}
