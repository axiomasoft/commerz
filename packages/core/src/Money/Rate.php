<?php

declare(strict_types=1);

namespace Commerz\Money;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** A non-negative ratio in basis points: 2000 = 20%. */
#[Api(since: '0.1')]
final readonly class Rate
{
    public function __construct(public int $basisPoints)
    {
        if ($basisPoints < 0) {
            throw new InvalidCommand('rate', 'basis points must not be negative', 'money.invalid_rate');
        }
    }

    public static function bp(int $basisPoints): self
    {
        return new self($basisPoints);
    }

    /** Parses a percent decimal such as '20' or '12.5'; at most two fraction digits (one basis point). */
    public static function percent(string $percent): self
    {
        if (preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/', $percent, $m) !== 1) {
            throw new InvalidCommand('rate', "'{$percent}' is not a percent with at most two decimals", 'money.invalid_rate');
        }

        $whole = (int) $m[1];
        $fraction = (int) str_pad($m[2] ?? '', 2, '0');

        if ($whole > intdiv(PHP_INT_MAX - $fraction, 100)) {
            throw new InvalidCommand('rate', 'percent is too large', 'money.invalid_rate');
        }

        return new self($whole * 100 + $fraction);
    }

    public function isZero(): bool
    {
        return $this->basisPoints === 0;
    }
}
