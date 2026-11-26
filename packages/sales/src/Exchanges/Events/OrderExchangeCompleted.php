<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Events;

use Commerz\Money\Money;
use Commerz\Sales\Exchanges\Data\ExchangeId;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Returns\Data\ReturnId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class OrderExchangeCompleted
{
    public function __construct(
        public ExchangeId $exchange,
        public OrderId $order,
        public ReturnId $return,
        public FulfillmentId $fulfillment,
        public Money $netDelta,
    ) {}
}
