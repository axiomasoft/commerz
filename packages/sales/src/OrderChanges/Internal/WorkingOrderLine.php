<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Internal;

use Commerz\Identity\SellerRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Sales\Orders\Data\OrderLineId;
use Packman\Attributes\Internal;

/** Mutable in-memory line used during dry-run calculation of an OrderChange. */
#[Internal]
final class WorkingOrderLine
{
    /** @param  list<Adjustment>  $ownAdjustments */
    public function __construct(
        public readonly OrderLineId $id,
        public readonly SellableSnapshot $sellable,
        public int $quantity,
        public ?Money $unitPrice,
        public ?Money $compareAt,
        public bool $taxIncluded,
        public string $priceSource,
        public array $ownAdjustments,
        public readonly int $cancelledQuantity,
        public readonly int $fulfilledQuantity,
        public readonly int $returnedQuantity,
        public readonly ?SellerRef $sellerRef,
        public readonly bool $isNew,
        public bool $needsPricing,
    ) {}
}
