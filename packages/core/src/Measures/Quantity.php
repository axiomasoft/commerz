<?php

declare(strict_types=1);

namespace Commerz\Measures;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** A non-negative whole count in the base unit of a stock item (pcs, g, ml, mm). */
#[Api(since: '0.1')]
final readonly class Quantity
{
    public function __construct(public int $value, public string $unit)
    {
        if ($value < 0) {
            throw new InvalidCommand('quantity', 'must not be negative', 'measures.invalid_quantity');
        }

        Unit::assert($unit);
    }

    public static function of(int $value, string $unit): self
    {
        return new self($value, $unit);
    }

    public static function pieces(int $value): self
    {
        return new self($value, 'pcs');
    }

    public function plus(self $other): self
    {
        $this->assertSameUnit($other);

        if ($this->value > PHP_INT_MAX - $other->value) {
            throw new InvalidCommand('quantity', 'result does not fit int64', 'measures.overflow');
        }

        return new self($this->value + $other->value, $this->unit);
    }

    public function minus(self $other): self
    {
        $this->assertSameUnit($other);

        if ($other->value > $this->value) {
            throw new InvalidCommand('quantity', 'result would be negative', 'measures.invalid_quantity');
        }

        return new self($this->value - $other->value, $this->unit);
    }

    public function isZero(): bool
    {
        return $this->value === 0;
    }

    /** @return int<-1, 1> */
    public function compare(self $other): int
    {
        $this->assertSameUnit($other);

        return $this->value <=> $other->value;
    }

    public function equals(self $other): bool
    {
        return $this->unit === $other->unit && $this->value === $other->value;
    }

    private function assertSameUnit(self $other): void
    {
        if ($this->unit !== $other->unit) {
            throw new InvalidCommand('unit', "{$this->unit} and {$other->unit} cannot be combined", 'measures.unit_mismatch');
        }
    }
}
