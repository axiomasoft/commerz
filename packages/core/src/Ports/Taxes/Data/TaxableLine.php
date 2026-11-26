<?php

declare(strict_types=1);

namespace Commerz\Ports\Taxes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Packman\Attributes\Api;

/** One line or delivery charge submitted to the read-only `TaxEngine` port. */
#[Api(since: '0.1')]
final readonly class TaxableLine
{
    public function __construct(
        public AdjustmentTarget $target,
        public ?string $lineId,
        public ?SellableRef $sellable,
        public ?string $taxClass,
        public int $quantity,
        public Money $base,
        public bool $included = false,
    ) {
        match ($target) {
            AdjustmentTarget::Line => $lineId !== null && $lineId !== ''
                ? null
                : throw new InvalidCommand('lineId', 'a line item needs a line id', 'taxes.invalid_line'),
            AdjustmentTarget::Delivery => $lineId === null && $sellable === null
                ? null
                : throw new InvalidCommand('lineId', 'a delivery item has neither a line id nor a sellable', 'taxes.invalid_line'),
            AdjustmentTarget::Order => throw new InvalidCommand('target', 'tax applies to a line or to delivery', 'taxes.invalid_line'),
        };

        if ($taxClass !== null && preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $taxClass) !== 1) {
            throw new InvalidCommand('taxClass', "'{$taxClass}' must match [a-z0-9][a-z0-9_.-]{0,63}", 'taxes.invalid_tax_class');
        }

        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'taxes.invalid_line');
        }

        if ($base->isNegative()) {
            throw new InvalidCommand('base', 'must not be negative', 'taxes.invalid_line');
        }
    }

    public static function line(
        string $lineId,
        Money $base,
        int $quantity = 1,
        ?string $taxClass = null,
        bool $included = false,
        ?SellableRef $sellable = null,
    ): self {
        return new self(AdjustmentTarget::Line, $lineId, $sellable, $taxClass, $quantity, $base, $included);
    }

    public static function delivery(Money $base, ?string $taxClass = null, bool $included = false): self
    {
        return new self(AdjustmentTarget::Delivery, null, null, $taxClass, 1, $base, $included);
    }

    public function key(): string
    {
        return $this->target->value.'|'.($this->lineId ?? '');
    }
}
