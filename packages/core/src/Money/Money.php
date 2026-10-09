<?php

declare(strict_types=1);

namespace Commerz\Money;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode as BrickRoundingMode;
use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** An amount in integer minor units of one currency. Arithmetic never leaves int64 silently. */
#[Api(since: '0.1')]
final readonly class Money
{
    public function __construct(public int $amountMinor, public Currency $currency) {}

    /**
     * Parses a decimal string by the currency exponent: Money::of('19.90', 'RUB') is 1990 minor units.
     * Extra fraction digits are rejected, never rounded.
     */
    public static function of(string $amount, Currency|string $currency): self
    {
        $currency = is_string($currency) ? Currency::of($currency) : $currency;

        if (preg_match('/\A-?\d+(?:\.\d+)?\z/', $amount) !== 1) {
            throw new InvalidCommand('amount', "'{$amount}' is not a decimal number", 'money.invalid_amount');
        }

        try {
            $minor = BigDecimal::of($amount)
                ->toScale($currency->exponent, BrickRoundingMode::Unnecessary)
                ->getUnscaledValue()
                ->toInt();
        } catch (MathException) {
            throw new InvalidCommand(
                'amount',
                "'{$amount}' does not fit {$currency->code} minor units exactly",
                'money.invalid_amount',
            );
        }

        return new self($minor, $currency);
    }

    public static function ofMinor(int $amountMinor, Currency|string $currency): self
    {
        return new self($amountMinor, is_string($currency) ? Currency::of($currency) : $currency);
    }

    public static function zero(Currency|string $currency): self
    {
        return self::ofMinor(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->amountMinor + $other->amountMinor), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checked($this->amountMinor - $other->amountMinor), $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self(self::checked($this->amountMinor * $factor), $this->currency);
    }

    public function negate(): self
    {
        return new self(self::checked(-$this->amountMinor), $this->currency);
    }

    /** The share of this amount at `$rate`, rounded to a whole minor unit. */
    public function percentage(Rate $rate, RoundingMode $mode): self
    {
        try {
            $minor = BigDecimal::of($this->amountMinor)
                ->multipliedBy($rate->basisPoints)
                ->dividedBy(10_000, 0, $mode->toBrick())
                ->toInt();
        } catch (MathException) {
            throw new InvalidCommand('amount', 'result does not fit int64', 'money.overflow');
        }

        return new self($minor, $this->currency);
    }

    /**
     * The part of this amount that is already tax at `$rate`: `round(amount × bp / (10000 + bp))`, to a whole minor
     * unit. For a gross price of 1220.00 at 22% it is 220.00.
     */
    public function includedPart(Rate $rate, RoundingMode $mode): self
    {
        try {
            $minor = BigDecimal::of($this->amountMinor)
                ->multipliedBy($rate->basisPoints)
                ->dividedBy(BigDecimal::of($rate->basisPoints)->plus(10_000), 0, $mode->toBrick())
                ->toInt();
        } catch (MathException) {
            throw new InvalidCommand('amount', 'result does not fit int64', 'money.overflow');
        }

        return new self($minor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function isPositive(): bool
    {
        return $this->amountMinor > 0;
    }

    /** @return int<-1, 1> */
    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor <=> $other->amountMinor;
    }

    public function equals(self $other): bool
    {
        return $this->currency->equals($other->currency) && $this->amountMinor === $other->amountMinor;
    }

    /** The amount as a decimal string by the currency exponent, e.g. '19.90'. */
    public function toDecimal(): string
    {
        return (string) BigDecimal::ofUnscaledValue($this->amountMinor, $this->currency->exponent);
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw new CurrencyMismatch($this->currency->code, $other->currency->code);
        }
    }

    private static function checked(int|float $result): int
    {
        if (! is_int($result)) {
            throw new InvalidCommand('amount', 'result does not fit int64', 'money.overflow');
        }

        return $result;
    }
}
