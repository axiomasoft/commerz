<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Events;

use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Refunds\Data\RefundId;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Packman\Attributes\Api;

/** Dispatched after a change of the status of a refund commits. */
#[Api(since: '0.1')]
final readonly class RefundStatusChanged
{
    public function __construct(public RefundId $refund, public OrderId $order, public RefundStatus $status) {}
}
