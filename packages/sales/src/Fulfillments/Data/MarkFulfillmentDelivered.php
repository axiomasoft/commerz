<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\ActorRef;

#[Api(since: '0.1')]
final readonly class MarkFulfillmentDelivered
{
    public function __construct(public FulfillmentId $fulfillment, public ?ActorRef $actor = null) {}
}
