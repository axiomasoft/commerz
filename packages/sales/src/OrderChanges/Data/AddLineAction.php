<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Identity\SellerRef;
use Commerz\Money\Money;
use Commerz\Sales\OrderChanges\Enums\OrderChangeActionType;
use Override;
use Packman\Attributes\Api;

/** Adds a new sellable line to the order, optionally overriding its unit price. */
#[Api(since: '0.1')]
final readonly class AddLineAction implements OrderChangeAction
{
    private const string CODE = 'sales.invalid_order_change';

    public function __construct(
        public SellableRef $sellable,
        public int $quantity,
        public ?Money $unitPriceOverride = null,
        public ?SellerRef $sellerRef = null,
    ) {
        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', self::CODE);
        }

        if ($unitPriceOverride !== null && $unitPriceOverride->isNegative()) {
            throw new InvalidCommand('unitPriceOverride', 'must not be negative', self::CODE);
        }
    }

    #[Override]
    public function type(): OrderChangeActionType
    {
        return OrderChangeActionType::AddLine;
    }

    #[Override]
    public function toPayload(): array
    {
        return [
            'sellable_ref' => (string) $this->sellable,
            'quantity' => $this->quantity,
            'unit_price_override' => $this->unitPriceOverride === null ? null : [
                'amount_minor' => $this->unitPriceOverride->amountMinor,
                'currency' => $this->unitPriceOverride->currency->code,
            ],
            'seller_ref' => $this->sellerRef === null ? null : (string) $this->sellerRef,
        ];
    }
}
