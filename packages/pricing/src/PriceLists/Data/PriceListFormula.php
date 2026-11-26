<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Data;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\RoundingMode;
use Commerz\Pricing\PriceLists\Enums\FormulaKind;
use Packman\Attributes\Api;

/**
 * Deterministic price derivation formula from a parent price list (`margin_percent`, `fixed_delta`, or `fx_rate_with_spread`).
 * Uses exact `int` basis points / minor units and `brick/math` `BigRational` — zero floats.
 */
#[Api(since: '0.1')]
final readonly class PriceListFormula
{
    public function __construct(
        public FormulaKind $kind,
        public ?int $marginBp = null,
        public ?int $deltaMinor = null,
        public ?string $fxRate = null,
        public int $spreadBp = 0,
        public RoundingMode $rounding = RoundingMode::HalfUp,
    ) {
        match ($kind) {
            FormulaKind::MarginPercent => self::assertMargin($marginBp, $deltaMinor, $fxRate, $spreadBp),
            FormulaKind::FixedDelta => self::assertFixedDelta($marginBp, $deltaMinor, $fxRate, $spreadBp),
            FormulaKind::FxRateWithSpread => self::assertFxRate($marginBp, $deltaMinor, $fxRate, $spreadBp),
        };
    }

    /**
     * Signed basis-point markup or markdown, e.g. `2000` (`+20%`) or `-1500` (`-15%`).
     */
    public static function marginPercent(int $marginBp, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        return new self(FormulaKind::MarginPercent, marginBp: $marginBp, rounding: $rounding);
    }

    /**
     * Parses a signed percent string with up to two decimals (e.g. `'-15'`, `'20'`, `'12.5'`, `'+7.25'`).
     */
    public static function marginPercentDecimal(string $percent, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        if (preg_match('/\A([+-])?(\d+)(?:\.(\d{1,2}))?\z/', $percent, $matches) !== 1) {
            throw new InvalidCommand('formula', "'{$percent}' is not a valid percent with at most two decimals", 'pricing.invalid_formula');
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $whole = (int) $matches[2];
        $fraction = (int) str_pad($matches[3] ?? '', 2, '0');
        $bp = $sign * ($whole * 100 + $fraction);

        return self::marginPercent($bp, $rounding);
    }

    /**
     * Signed minor-unit adjustment in the price list currency, e.g. `500` (`+5.00`) or `-300` (`-3.00`).
     */
    public static function fixedDelta(int $deltaMinor): self
    {
        return new self(FormulaKind::FixedDelta, deltaMinor: $deltaMinor);
    }

    /**
     * Foreign-exchange conversion with a positive decimal rate string (units of target currency per 1 major unit of parent currency)
     * and signed/non-negative spread in basis points (e.g. `150` = `+1.50%`).
     */
    public static function fxRateWithSpread(
        string $fxRate,
        int $spreadBp = 0,
        RoundingMode $rounding = RoundingMode::HalfUp,
    ): self {
        return new self(FormulaKind::FxRateWithSpread, fxRate: $fxRate, spreadBp: $spreadBp, rounding: $rounding);
    }

    public function assertCurrenciesCompatible(Currency $parentCurrency, Currency $childCurrency): void
    {
        if (in_array($this->kind, [FormulaKind::MarginPercent, FormulaKind::FixedDelta], true)
            && ! $parentCurrency->equals($childCurrency)) {
            throw new InvalidCommand(
                'formula',
                "formula '{$this->kind->value}' requires parent currency ({$parentCurrency->code}) to match child currency ({$childCurrency->code}); use 'fx_rate_with_spread' for cross-currency lists",
                'pricing.currency_mismatch',
            );
        }
    }

    public function evaluate(Money $parentAmount, Currency $targetCurrency, ?RoundingRule $roundingRule = null): Money
    {
        $this->assertCurrenciesCompatible($parentAmount->currency, $targetCurrency);

        try {
            $minor = match ($this->kind) {
                FormulaKind::MarginPercent => BigRational::ofFraction(10_000 + ($this->marginBp ?? 0), 10_000)
                    ->multipliedBy($parentAmount->amountMinor)
                    ->toScale(0, $this->rounding->toBrick())
                    ->toInt(),
                FormulaKind::FixedDelta => BigDecimal::of($parentAmount->amountMinor)
                    ->plus($this->deltaMinor ?? 0)
                    ->toInt(),
                FormulaKind::FxRateWithSpread => BigRational::of(BigDecimal::ofUnscaledValue($parentAmount->amountMinor, $parentAmount->currency->exponent))
                    ->multipliedBy(BigRational::of($this->fxRate ?? '1'))
                    ->multipliedBy(BigRational::ofFraction(10_000 + $this->spreadBp, 10_000))
                    ->multipliedBy(10 ** $targetCurrency->exponent)
                    ->toScale(0, $this->rounding->toBrick())
                    ->toInt(),
            };
        } catch (MathException) {
            throw new InvalidCommand('amount', 'formula result does not fit int64', 'money.overflow');
        }

        $clamped = max(0, $minor);
        $derived = Money::ofMinor($clamped, $targetCurrency);

        return $roundingRule instanceof RoundingRule ? $roundingRule->apply($derived) : $derived;
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return match ($this->kind) {
            FormulaKind::MarginPercent => [
                'kind' => $this->kind->value,
                'margin_bp' => $this->marginBp ?? 0,
                'rounding' => $this->rounding->value,
            ],
            FormulaKind::FixedDelta => [
                'kind' => $this->kind->value,
                'delta_minor' => $this->deltaMinor ?? 0,
            ],
            FormulaKind::FxRateWithSpread => [
                'kind' => $this->kind->value,
                'fx_rate' => $this->fxRate ?? '1',
                'spread_bp' => $this->spreadBp,
                'rounding' => $this->rounding->value,
            ],
        };
    }

    /** @param  array<array-key, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $kindRaw = $data['kind'] ?? null;
        $kind = is_string($kindRaw) ? FormulaKind::tryFrom($kindRaw) : null;

        if (! $kind instanceof FormulaKind) {
            throw new InvalidCommand('formula', 'invalid formula kind', 'pricing.invalid_formula');
        }

        $roundingRaw = $data['rounding'] ?? RoundingMode::HalfUp->value;
        $rounding = is_string($roundingRaw) ? RoundingMode::tryFrom($roundingRaw) : null;

        if (! $rounding instanceof RoundingMode) {
            throw new InvalidCommand('formula', 'invalid formula rounding mode', 'pricing.invalid_formula');
        }

        return match ($kind) {
            FormulaKind::MarginPercent => new self(
                kind: FormulaKind::MarginPercent,
                marginBp: is_int($data['margin_bp'] ?? null) ? $data['margin_bp'] : throw new InvalidCommand('formula', 'margin_bp must be an integer', 'pricing.invalid_formula'),
                rounding: $rounding,
            ),
            FormulaKind::FixedDelta => new self(
                kind: FormulaKind::FixedDelta,
                deltaMinor: is_int($data['delta_minor'] ?? null) ? $data['delta_minor'] : throw new InvalidCommand('formula', 'delta_minor must be an integer', 'pricing.invalid_formula'),
            ),
            FormulaKind::FxRateWithSpread => new self(
                kind: FormulaKind::FxRateWithSpread,
                fxRate: is_string($data['fx_rate'] ?? null) ? $data['fx_rate'] : throw new InvalidCommand('formula', 'fx_rate must be a decimal string', 'pricing.invalid_formula'),
                spreadBp: is_int($data['spread_bp'] ?? 0) ? ($data['spread_bp'] ?? 0) : throw new InvalidCommand('formula', 'spread_bp must be an integer', 'pricing.invalid_formula'),
                rounding: $rounding,
            ),
        };
    }

    private static function assertMargin(?int $marginBp, ?int $deltaMinor, ?string $fxRate, int $spreadBp): void
    {
        if ($marginBp === null || $deltaMinor !== null || $fxRate !== null || $spreadBp !== 0) {
            throw new InvalidCommand('formula', 'margin_percent requires marginBp only', 'pricing.invalid_formula');
        }

        if ($marginBp < -10_000 || $marginBp > 10_000_000) {
            throw new InvalidCommand('marginBp', 'marginBp must be between -10000 (-100%) and 10000000', 'pricing.invalid_formula');
        }
    }

    private static function assertFixedDelta(?int $marginBp, ?int $deltaMinor, ?string $fxRate, int $spreadBp): void
    {
        if ($deltaMinor === null || $marginBp !== null || $fxRate !== null || $spreadBp !== 0) {
            throw new InvalidCommand('formula', 'fixed_delta requires deltaMinor only', 'pricing.invalid_formula');
        }
    }

    private static function assertFxRate(?int $marginBp, ?int $deltaMinor, ?string $fxRate, int $spreadBp): void
    {
        if ($fxRate === null || $marginBp !== null || $deltaMinor !== null) {
            throw new InvalidCommand('formula', 'fx_rate_with_spread requires fxRate', 'pricing.invalid_formula');
        }

        if (preg_match('/\A\d+(?:\.\d{1,12})?\z/', $fxRate) !== 1) {
            throw new InvalidCommand('fxRate', "'{$fxRate}' must be a positive decimal string with up to 12 fraction digits", 'pricing.invalid_formula');
        }

        try {
            if (! BigDecimal::of($fxRate)->isPositive()) {
                throw new InvalidCommand('fxRate', 'fxRate must be strictly positive', 'pricing.invalid_formula');
            }
        } catch (MathException) {
            throw new InvalidCommand('fxRate', "'{$fxRate}' is not a valid decimal", 'pricing.invalid_formula');
        }

        if ($spreadBp <= -10_000 || $spreadBp > 10_000_000) {
            throw new InvalidCommand('spreadBp', 'spreadBp must be greater than -10000 and at most 10000000', 'pricing.invalid_formula');
        }
    }
}
