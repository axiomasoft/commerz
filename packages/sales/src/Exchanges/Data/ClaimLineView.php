<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Orders\Data\OrderLineId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ClaimLineView
{
    public function __construct(
        public string $id,
        public ?OrderLineId $sourceOrderLineId,
        public SellableRef $sellable,
        public string $sku,
        public string $name,
        public SellableKind $kind,
        public bool $requiresShipping,
        public ?int $weightGrams,
        public int $quantity,
        public Money $unitPrice,
        public Money $subtotal,
        public Money $taxTotal,
        public Money $total,
    ) {}
}
