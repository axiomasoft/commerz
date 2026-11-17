<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Events;

use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

/** Dispatched after a fulfillment is shipped (by hand or through a booking) and the change commits. */
#[Api(since: '0.1')]
final readonly class FulfillmentShipped
{
    public function __construct(public FulfillmentId $fulfillment, public OrderId $order, public ?string $tracking) {}
}
