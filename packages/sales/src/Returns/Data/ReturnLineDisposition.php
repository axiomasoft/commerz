<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Returns\Enums\ReturnDisposition;
use Commerz\Sales\Returns\Enums\ReturnReason;
use Packman\Attributes\Api;

/**
 * Explicit disposition (`Restock` or `DamagedWriteOff`) for returned units of an order line.
 */
#[Api(since: '0.1')]
final readonly class ReturnLineDisposition
{
    public function __construct(
        public OrderLineId $lineId,
        public int $quantity,
        public ReturnDisposition $disposition,
        public ?ReturnReason $reason = null,
    ) {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'sales.invalid_quantity');
        }
    }
}
