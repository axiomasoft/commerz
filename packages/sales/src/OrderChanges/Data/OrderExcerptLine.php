<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Identity\SellableRef;
use Commerz\Identity\SellerRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Orders\Data\OrderLineView;
use Packman\Attributes\Api;

/** Snapshot of one order line inside an `OrderExcerpt` (before or after an OrderChange). */
#[Api(since: '0.1')]
final readonly class OrderExcerptLine
{
    /**
     * @param  array<string, string>  $options
     * @param  list<Adjustment>  $adjustments
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
        public ?SellerRef $sellerRef = null,
        public bool $isNew = false,
    ) {}

    public static function fromOrderLine(OrderLineView $line): self
    {
        return new self(
            id: $line->id,
            sellable: $line->sellable,
            sku: $line->sku,
            name: $line->name,
            kind: $line->kind,
            requiresShipping: $line->requiresShipping,
            options: $line->options,
            taxClass: $line->taxClass,
            weightGrams: $line->weightGrams,
            quantity: $line->quantity,
            unitPrice: $line->unitPrice,
            compareAt: $line->compareAt,
            taxIncluded: $line->taxIncluded,
            priceSource: $line->priceSource,
            subtotal: $line->subtotal,
            adjustments: $line->adjustments,
            taxLines: $line->taxLines,
            total: $line->total,
            cancelledQuantity: $line->cancelledQuantity,
            fulfilledQuantity: $line->fulfilledQuantity,
            returnedQuantity: $line->returnedQuantity,
            sellerRef: $line->sellerRef,
            isNew: false,
        );
    }
}
