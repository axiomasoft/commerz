<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Sales\Orders\Data\OrderLineId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ExchangeInboundLineView
{
    public function __construct(
        public OrderLineId $orderLineId,
        public SellableRef $sellable,
        public string $sku,
        public string $name,
        public int $quantity,
        public Money $creditTotal,
    ) {}
}
