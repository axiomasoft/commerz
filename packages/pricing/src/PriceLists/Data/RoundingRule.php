<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Data;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\RoundingMode;
use Commerz\Pricing\PriceLists\Enums\RoundingStrategy;
use Packman\Attributes\Api;

/**
 * Psychological and step-based price rounding rule using exact integer / `brick/math` arithmetic (zero floats).
 * Supports `.99`, `.90`, multiples of `5` or `10` major units, and custom minor-unit steps.
 */
#[Api(since: '0.1')]
final readonly class RoundingRule
{
    public function __construct(
        public RoundingStrategy $strategy,
        public RoundingMode $mode = RoundingMode::HalfUp,
        public ?int $stepMinor = null,
    ) {
        if ($strategy === RoundingStrategy::MinorMultiple) {
            if ($stepMinor === null || $stepMinor < 1) {
                throw new InvalidCommand('stepMinor', 'minor_multiple requires stepMinor >= 1', 'pricing.invalid_rounding_rule');
            }
        } elseif ($stepMinor !== null) {
            throw new InvalidCommand('stepMinor', "stepMinor is only allowed for strategy 'minor_multiple'", 'pricing.invalid_rounding_rule');
        }
    }

    public static function none(): self
    {
        return new self(RoundingStrategy::None);
    }

    public static function ending99(RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self(RoundingStrategy::Ending99, $mode);
    }

    public static function ending90(RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self(RoundingStrategy::Ending90, $mode);
    }

    public static function multipleOf5(RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self(RoundingStrategy::MultipleOf5, $mode);
    }

    public static function multipleOf10(RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self(RoundingStrategy::MultipleOf10, $mode);
    }

    public static function minorMultiple(int $stepMinor, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self(RoundingStrategy::MinorMultiple, $mode, $stepMinor);
    }

    public function assertCompatibleWith(Currency $currency): void
    {
        if ($this->strategy === RoundingStrategy::Ending99 && $currency->exponent < 2) {
            throw new InvalidCommand(
                'roundingRule',
                "strategy 'ending_99' requires a currency with at least 2 decimal places (got {$currency->code})",
                'pricing.invalid_rounding_rule',
            );
        }

        if ($this->strategy === RoundingStrategy::Ending90 && $currency->exponent < 1) {
            throw new InvalidCommand(
                'roundingRule',
                "strategy 'ending_90' requires a currency with at least 1 decimal place (got {$currency->code})",
                'pricing.invalid_rounding_rule',
            );
        }
    }

    public function apply(Money $amount): Money
    {
        if ($amount->amountMinor < 0) {
            throw new InvalidCommand('amount', 'must not be negative', 'pricing.invalid_amount');
        }

        $this->assertCompatibleWith($amount->currency);

        if ($amount->amountMinor === 0) {
            return $amount;
        }

        $exponent = $amount->currency->exponent;
        $major = 10 ** $exponent;

        try {
            $minor = match ($this->strategy) {
                RoundingStrategy::Ending99 => self::roundWithOffset(
                    amountMinor: $amount->amountMinor,
                    step: $major,
                    offset: 99 * (10 ** ($exponent - 2)),
                    mode: $this->mode,
                ),
                RoundingStrategy::Ending90 => self::roundWithOffset(
                    amountMinor: $amount->amountMinor,
                    step: $major,
                    offset: 9 * (10 ** ($exponent - 1)),
                    mode: $this->mode,
                ),
                RoundingStrategy::MultipleOf5 => self::roundToStep(
                    amountMinor: $amount->amountMinor,
                    step: 5 * $major,
                    mode: $this->mode,
                ),
                RoundingStrategy::MultipleOf10 => self::roundToStep(
                    amountMinor: $amount->amountMinor,
                    step: 10 * $major,
                    mode: $this->mode,
                ),
                RoundingStrategy::MinorMultiple => self::roundToStep(
                    amountMinor: $amount->amountMinor,
                    step: $this->stepMinor ?? 1,
                    mode: $this->mode,
                ),
                RoundingStrategy::None => $amount->amountMinor,
            };
        } catch (MathException) {
            throw new InvalidCommand('amount', 'rounded result does not fit int64', 'money.overflow');
        }

        return Money::ofMinor($minor, $amount->currency);
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        $payload = [
            'strategy' => $this->strategy->value,
            'mode' => $this->mode->value,
        ];

        if ($this->stepMinor !== null) {
            $payload['step_minor'] = $this->stepMinor;
        }

        return $payload;
    }

    /** @param  array<array-key, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $strategyRaw = $data['strategy'] ?? null;
        $strategy = is_string($strategyRaw) ? RoundingStrategy::tryFrom($strategyRaw) : null;

        if (! $strategy instanceof RoundingStrategy) {
            throw new InvalidCommand('roundingRule', 'invalid rounding strategy', 'pricing.invalid_rounding_rule');
        }

        $modeRaw = $data['mode'] ?? RoundingMode::HalfUp->value;
        $mode = is_string($modeRaw) ? RoundingMode::tryFrom($modeRaw) : null;

        if (! $mode instanceof RoundingMode) {
            throw new InvalidCommand('roundingRule', 'invalid rounding mode', 'pricing.invalid_rounding_rule');
        }

        $stepMinor = $data['step_minor'] ?? null;

        if ($stepMinor !== null && ! is_int($stepMinor)) {
            throw new InvalidCommand('roundingRule', 'step_minor must be an integer', 'pricing.invalid_rounding_rule');
        }

        return new self($strategy, $mode, $stepMinor);
    }

    private static function roundWithOffset(int $amountMinor, int $step, int $offset, RoundingMode $mode): int
    {
        $shifted = $amountMinor - $offset;

        if ($shifted <= 0) {
            return $offset;
        }

        $units = max(0, BigDecimal::of($shifted)->dividedBy($step, 0, $mode->toBrick())->toInt());

        return BigDecimal::of($units)->multipliedBy($step)->plus($offset)->toInt();
    }

    private static function roundToStep(int $amountMinor, int $step, RoundingMode $mode): int
    {
        $units = max(0, BigDecimal::of($amountMinor)->dividedBy($step, 0, $mode->toBrick())->toInt());

        return BigDecimal::of($units)->multipliedBy($step)->toInt();
    }
}
