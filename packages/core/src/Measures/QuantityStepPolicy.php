<?php

declare(strict_types=1);

namespace Commerz\Measures;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\RoundingMode;
use Packman\Attributes\Api;

/**
 * Defines allowed whole-unit quantities for a sellable: minimum, step increment, optional maximum, and base unit
 * (e.g. min 500 g, step 100 g, max 5000 g for weighted goods, or min 1, step 1 for piece goods).
 */
#[Api(since: '0.1')]
final readonly class QuantityStepPolicy
{
    public function __construct(
        public int $min,
        public int $step,
        public ?int $max = null,
        public string $unit = 'pcs',
    ) {
        if ($min < 1) {
            throw new InvalidCommand('min', 'must be at least 1', 'measures.invalid_step_policy');
        }

        if ($step < 1) {
            throw new InvalidCommand('step', 'must be at least 1', 'measures.invalid_step_policy');
        }

        if ($max !== null && $max < $min) {
            throw new InvalidCommand('max', 'must be greater than or equal to min', 'measures.invalid_step_policy');
        }

        Unit::assert($unit);
    }

    public static function of(int $min, int $step, ?int $max, string $unit): self
    {
        return new self($min, $step, $max, $unit);
    }

    public static function pieces(int $min = 1, int $step = 1, ?int $max = null): self
    {
        return new self($min, $step, $max, 'pcs');
    }

    /** Highest valid quantity permitted by the policy, or null when unbounded. */
    public function maxValid(): ?int
    {
        if ($this->max === null) {
            return null;
        }

        return $this->max - (($this->max - $this->min) % $this->step);
    }

    public function isValid(int|Quantity $quantity): bool
    {
        if ($quantity instanceof Quantity) {
            if ($quantity->unit !== $this->unit) {
                return false;
            }

            $value = $quantity->value;
        } else {
            $value = $quantity;
        }

        if ($value < $this->min) {
            return false;
        }

        if ($this->max !== null && $value > $this->max) {
            return false;
        }

        return (($value - $this->min) % $this->step) === 0;
    }

    public function assertValid(int|Quantity $quantity): void
    {
        $value = $this->extractValue($quantity);

        if ($value < $this->min) {
            throw new InvalidCommand('quantity', "must be at least {$this->min} {$this->unit}", 'measures.invalid_quantity_step');
        }

        if ($this->max !== null && $value > $this->max) {
            throw new InvalidCommand('quantity', "must not exceed {$this->max} {$this->unit}", 'measures.invalid_quantity_step');
        }

        if ((($value - $this->min) % $this->step) !== 0) {
            throw new InvalidCommand(
                'quantity',
                "must align with step {$this->step} from minimum {$this->min} {$this->unit}",
                'measures.invalid_quantity_step',
            );
        }
    }

    /**
     * Rounds a non-negative quantity to a valid step using an explicit RoundingMode and clamps to [min, maxValid].
     */
    public function normalize(int $quantity, RoundingMode $mode = RoundingMode::HalfUp): int
    {
        if ($quantity < 0) {
            throw new InvalidCommand('quantity', 'must not be negative', 'measures.invalid_quantity');
        }

        if ($quantity <= $this->min) {
            return $this->min;
        }

        $maxValid = $this->maxValid();

        if ($maxValid !== null && $quantity >= $maxValid) {
            return $maxValid;
        }

        $offset = $quantity - $this->min;
        $steps = BigDecimal::of($offset)
            ->dividedBy($this->step, 0, $mode->toBrick())
            ->toBigInteger();

        $candidate = BigInteger::of($this->min)->plus($steps->multipliedBy($this->step));

        if ($maxValid !== null && $candidate->compareTo($maxValid) > 0) {
            return $maxValid;
        }

        try {
            return $candidate->toInt();
        } catch (MathException) {
            throw new InvalidCommand('quantity', 'normalized quantity does not fit int64', 'measures.overflow');
        }
    }

    /** Normalizes a Quantity value object, checking that its unit matches this policy. */
    public function normalizeQuantity(Quantity $quantity, RoundingMode $mode = RoundingMode::HalfUp): Quantity
    {
        $this->assertSameUnit($quantity);

        return new Quantity($this->normalize($quantity->value, $mode), $this->unit);
    }

    public function equals(self $other): bool
    {
        return $this->min === $other->min
            && $this->step === $other->step
            && $this->max === $other->max
            && $this->unit === $other->unit;
    }

    private function extractValue(int|Quantity $quantity): int
    {
        if ($quantity instanceof Quantity) {
            $this->assertSameUnit($quantity);

            return $quantity->value;
        }

        return $quantity;
    }

    private function assertSameUnit(Quantity $quantity): void
    {
        if ($quantity->unit !== $this->unit) {
            throw new InvalidCommand('unit', "{$quantity->unit} does not match policy unit {$this->unit}", 'measures.unit_mismatch');
        }
    }
}
