<?php

declare(strict_types=1);

namespace Commerz\Money\Data;

use Commerz\Attributes\Api;
use InvalidArgumentException;

/** ISO 4217 код + число знаков минорной единицы (RUB 2, JPY 0, KWD 3). Источник exponent — порт CurrencyMetadata. */
#[Api(since: '1.0')]
final readonly class Currency
{
    public function __construct(public string $code, public int $exponent)
    {
        if (preg_match('/\A[A-Z]{3}\z/D', $code) !== 1) {
            throw new InvalidArgumentException("Currency code [{$code}] must be ISO 4217 alpha-3.");
        }

        if ($exponent < 0 || $exponent > 4) {
            throw new InvalidArgumentException("Currency exponent [{$exponent}] is out of range 0..4.");
        }
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
