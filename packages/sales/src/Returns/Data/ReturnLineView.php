<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Data;

use Commerz\Sales\Orders\Data\OrderLineId;
use Packman\Attributes\Api;

/** Units of one order line in a return and how many of them went back on hand. */
#[Api(since: '0.1')]
final readonly class ReturnLineView
{
    public function __construct(public OrderLineId $lineId, public int $quantity, public int $restocked) {}
}
