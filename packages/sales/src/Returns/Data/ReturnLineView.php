<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Data;

use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Returns\Enums\ReturnDisposition;
use Commerz\Sales\Returns\Enums\ReturnReason;
use Packman\Attributes\Api;

/** Units of one order line in a return, how many went back on hand, and how many were written off as damaged. */
#[Api(since: '0.1')]
final readonly class ReturnLineView
{
    public function __construct(
        public OrderLineId $lineId,
        public int $quantity,
        public int $restocked,
        public int $writtenOff = 0,
        public ?ReturnDisposition $disposition = null,
        public ?ReturnReason $reason = null,
    ) {}
}
