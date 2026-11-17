<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Identity\SellableRef;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Orders\Data\OrderLineId;
use Packman\Attributes\Internal;

/** A stored order line with its ledger (ORD-03), as read under the lock of the order. */
#[Internal]
final readonly class OrderLineRow
{
    public function __construct(
        public OrderLineId $id,
        public SellableRef $sellable,
        public SellableKind $kind,
        public int $quantity,
        public int $cancelled,
        public int $fulfilled,
        public int $returned,
    ) {}

    /** Units neither cancelled nor fulfilled yet. */
    public function outstanding(): int
    {
        return $this->quantity - $this->cancelled - $this->fulfilled;
    }
}
