<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Events;

use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Enums\PaymentState;
use Packman\Attributes\Api;

/** Dispatched after a change of the payment state of the order commits. */
#[Api(since: '0.1')]
final readonly class OrderPaymentStateChanged
{
    public function __construct(public OrderId $order, public PaymentState $from, public PaymentState $to) {}
}
