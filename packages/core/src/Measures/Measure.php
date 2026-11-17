<?php

declare(strict_types=1);

namespace Commerz\Measures;

use Brick\Math\BigDecimal;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** A decimal attribute value with its unit, e.g. '1.250' kg. Kept as a string; never a float. */
#[Api(since: '0.1')]
final readonly class Measure
{
    /** @param numeric-string $value */
    public function __construct(public string $value, public string $unit)
    {
        if (preg_match('/\A-?\d{1,30}(?:\.\d{1,12})?\z/', $value) !== 1) {
            throw new InvalidCommand('value', "'{$value}' is not a plain decimal number", 'measures.invalid_measure');
        }

        Unit::assert($unit);
    }

    public static function of(string $value, string $unit): self
    {
        if (! is_numeric($value)) {
            throw new InvalidCommand('value', "'{$value}' is not a plain decimal number", 'measures.invalid_measure');
        }

        return new self($value, $unit);
    }

    /** @return int<-1, 1> */
    public function compare(self $other): int
    {
        if ($this->unit !== $other->unit) {
            throw new InvalidCommand('unit', "{$this->unit} and {$other->unit} cannot be compared", 'measures.unit_mismatch');
        }

        return BigDecimal::of($this->value)->compareTo($other->value);
    }

    /** Equal by numeric value: '1.50' equals '1.5'. */
    public function equals(self $other): bool
    {
        return $this->unit === $other->unit && BigDecimal::of($this->value)->isEqualTo($other->value);
    }
}
