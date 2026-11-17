<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Events;

use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Returns\Data\ReturnId;
use Packman\Attributes\Api;

/** Dispatched after the acceptance of a return commits. */
#[Api(since: '0.1')]
final readonly class ReturnReceived
{
    public function __construct(public ReturnId $return, public OrderId $order) {}
}
