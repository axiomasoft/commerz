<?php

declare(strict_types=1);

namespace Commerz\Money;

use Packman\Attributes\Api;
use Stringable;

/** ISO 4217 code with its minor-unit exponent, taken from a built-in table. */
#[Api(since: '0.1')]
final readonly class Currency implements Stringable
{
    /** @var array<string, int<0, 3>> code => exponent */
    private const array EXPONENTS = [
        'AED' => 2, 'AMD' => 2, 'ARS' => 2, 'AUD' => 2, 'AZN' => 2, 'BGN' => 2, 'BHD' => 3, 'BRL' => 2,
        'BYN' => 2, 'CAD' => 2, 'CHF' => 2, 'CLP' => 0, 'CNY' => 2, 'CZK' => 2, 'DKK' => 2, 'EGP' => 2,
        'EUR' => 2, 'GBP' => 2, 'GEL' => 2, 'HKD' => 2, 'HUF' => 2, 'IDR' => 2, 'ILS' => 2, 'INR' => 2,
        'ISK' => 0, 'JOD' => 3, 'JPY' => 0, 'KGS' => 2, 'KRW' => 0, 'KWD' => 3, 'KZT' => 2, 'MDL' => 2,
        'MXN' => 2, 'NOK' => 2, 'NZD' => 2, 'OMR' => 3, 'PLN' => 2, 'RON' => 2, 'RSD' => 2, 'RUB' => 2,
        'SAR' => 2, 'SEK' => 2, 'SGD' => 2, 'THB' => 2, 'TJS' => 2, 'TMT' => 2, 'TND' => 3, 'TRY' => 2,
        'UAH' => 2, 'UGX' => 0, 'USD' => 2, 'UZS' => 2, 'VND' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'ZAR' => 2,
    ];

    private function __construct(
        public string $code,
        /** @var int<0, 3> */
        public int $exponent,
    ) {}

    /** @throws InvalidCurrency */
    public static function of(string $code): self
    {
        $exponent = self::EXPONENTS[$code] ?? null;

        if ($exponent === null) {
            throw new InvalidCurrency($code);
        }

        return new self($code, $exponent);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
