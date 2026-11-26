<?php

declare(strict_types=1);

namespace Commerz\Measures;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Money\RoundingMode;
use Packman\Attributes\Api;

/**
 * A price quoted per a whole number of base units, e.g. 250.00 RUB per 1000 g, 120.00 RUB per 1000 mm, or 10.00 RUB
 * per 1 pcs. All calculations use exact integer / arbitrary-precision decimal arithmetic with explicit rounding.
 */
#[Api(since: '0.1')]
final readonly class UnitPrice
{
    public function __construct(
        public Money $amount,
        public int $perUnits = 1,
        public string $unit = 'pcs',
    ) {
        if ($amount->isNegative()) {
            throw new InvalidCommand('amount', 'must not be negative', 'measures.invalid_unit_price');
        }

        if ($perUnits < 1) {
            throw new InvalidCommand('perUnits', 'must be at least 1', 'measures.invalid_unit_price');
        }

        Unit::assert($unit);
    }

    public static function of(Money $amount, int $perUnits, string $unit): self
    {
        return new self($amount, $perUnits, $unit);
    }

    public static function perPiece(Money $amount): self
    {
        return new self($amount, 1, 'pcs');
    }

    public static function perKilogram(Money $amount): self
    {
        return new self($amount, 1_000, 'g');
    }

    public static function perMeter(Money $amount): self
    {
        return new self($amount, 1_000, 'mm');
    }

    public static function perLiter(Money $amount): self
    {
        return new self($amount, 1_000, 'ml');
    }

    public function withAmount(Money $amount): self
    {
        return new self($amount, $this->perUnits, $this->unit);
    }

    /**
     * Computes `round(amount × quantity / perUnits)` in the currency's minor units with explicit rounding.
     */
    public function calculate(int|Quantity $quantity, RoundingMode $mode = RoundingMode::HalfUp): Money
    {
        if ($quantity instanceof Quantity) {
            if ($quantity->unit !== $this->unit) {
                throw new InvalidCommand('unit', "{$quantity->unit} does not match unit price unit {$this->unit}", 'measures.unit_mismatch');
            }

            $units = $quantity->value;
        } else {
            if ($quantity < 0) {
                throw new InvalidCommand('quantity', 'must not be negative', 'measures.invalid_quantity');
            }

            $units = $quantity;
        }

        try {
            $minor = BigDecimal::of($this->amount->amountMinor)
                ->multipliedBy($units)
                ->dividedBy($this->perUnits, 0, $mode->toBrick())
                ->toInt();
        } catch (MathException) {
            throw new InvalidCommand('quantity', 'result does not fit int64', 'money.overflow');
        }

        return new Money($minor, $this->amount->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount->equals($other->amount)
            && $this->perUnits === $other->perUnits
            && $this->unit === $other->unit;
    }
}
