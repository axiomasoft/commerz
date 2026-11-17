<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Events;

use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Packman\Attributes\Api;

/** Dispatched after a change of the status of a payment commits. */
#[Api(since: '0.1')]
final readonly class PaymentStatusChanged
{
    public function __construct(public PaymentId $payment, public OrderId $order, public PaymentStatus $status) {}
}
