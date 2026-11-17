<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Data;

use Commerz\Identity\ActorRef;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class MarkFulfillmentDelivered
{
    public function __construct(public FulfillmentId $fulfillment, public ?ActorRef $actor = null) {}
}
