<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Events;

use Commerz\Attributes\Api;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Orders\Data\OrderId;

/** Dispatched after the creation of a fulfillment commits. */
#[Api(since: '0.1')]
final readonly class FulfillmentCreated
{
    public function __construct(public FulfillmentId $fulfillment, public OrderId $order) {}
}
