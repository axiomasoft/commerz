<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\TaxLine;
use Packman\Attributes\Api;

/** A line of a placed order: the snapshot of the sellable and its price as quoted, and the ledger of the line (ORD-03). */
#[Api(since: '0.1')]
final readonly class OrderLineView
{
    /**
     * @param  array<string, string>  $options  display label => value
     * @param  list<Adjustment>  $adjustments  own line adjustments, then the line's shares of order-level ones
     * @param  list<TaxLine>  $taxLines
     */
    public function __construct(
        public OrderLineId $id,
        public SellableRef $sellable,
        public string $sku,
        public string $name,
        public SellableKind $kind,
        public bool $requiresShipping,
        public array $options,
        public ?string $taxClass,
        public ?int $weightGrams,
        public int $quantity,
        public Money $unitPrice,
        public ?Money $compareAt,
        public bool $taxIncluded,
        public string $priceSource,
        public Money $subtotal,
        public array $adjustments,
        public array $taxLines,
        public Money $total,
        public int $cancelledQuantity,
        public int $fulfilledQuantity,
        public int $returnedQuantity,
    ) {}

    /** Units neither cancelled nor fulfilled yet. */
    public function outstanding(): int
    {
        return $this->quantity - $this->cancelledQuantity - $this->fulfilledQuantity;
    }
}
