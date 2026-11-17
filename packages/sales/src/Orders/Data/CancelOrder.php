<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Identity\ActorRef;
use Commerz\Sales\Orders\Enums\CancelReason;
use Packman\Attributes\Api;

/** Cancels every unit of an order nothing of which is fulfilled yet; cancelling a cancelled order changes nothing. */
#[Api(since: '0.1')]
final readonly class CancelOrder
{
    public function __construct(public OrderId $order, public CancelReason $reason, public ?ActorRef $actor = null) {}
}
