<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;

/** The three states of an order before and after `OrderStatus::refresh`. */
final readonly class OrderStatusChange
{
    public function __construct(
        public OrderState $stateFrom,
        public OrderState $state,
        public PaymentState $paymentFrom,
        public PaymentState $payment,
        public FulfillmentState $fulfillmentFrom,
        public FulfillmentState $fulfillment,
    ) {}

    public function changed(): bool
    {
        return $this->stateFrom !== $this->state || $this->paymentFrom !== $this->payment || $this->fulfillmentFrom !== $this->fulfillment;
    }
}
