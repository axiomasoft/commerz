<?php

declare(strict_types=1);

namespace Commerz\Sellable;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Money\RoundingMode;
use Packman\Attributes\Api;

/**
 * Pricing policy of a {@see SellingPlan}: supports percentage discounts (e.g. Subscribe & Save 10%), fixed-amount
 * discounts, fixed prices, and optional post-introductory pricing after N billing cycles.
 */
#[Api(since: '0.1')]
final readonly class PricingPolicy
{
    public function __construct(
        public PricingAdjustmentKind $kind = PricingAdjustmentKind::None,
        public ?Rate $percentageOff = null,
        public ?Money $fixedAmount = null,
        public ?int $afterCycle = null,
        public ?self $afterCyclePolicy = null,
    ) {
        match ($kind) {
            PricingAdjustmentKind::None => $this->assertNone($percentageOff, $fixedAmount),
            PricingAdjustmentKind::PercentageOff => $this->assertPercentage($percentageOff, $fixedAmount),
            PricingAdjustmentKind::FixedAmountOff, PricingAdjustmentKind::FixedPrice => $this->assertFixedAmount($percentageOff, $fixedAmount),
        };

        if (($afterCycle === null) !== ($afterCyclePolicy === null)) {
            throw new InvalidCommand(
                'afterCycle',
                'afterCycle and afterCyclePolicy must be provided together',
                'sellable.invalid_pricing_policy',
            );
        }

        if ($afterCycle !== null && $afterCycle < 1) {
            throw new InvalidCommand('afterCycle', 'must be at least 1', 'sellable.invalid_pricing_policy');
        }

        if ($afterCyclePolicy !== null && $afterCyclePolicy->afterCycle !== null) {
            throw new InvalidCommand('afterCyclePolicy', 'nested afterCyclePolicy is not supported', 'sellable.invalid_pricing_policy');
        }
    }

    public static function none(): self
    {
        return new self(PricingAdjustmentKind::None);
    }

    public static function percentageOff(Rate $rate): self
    {
        return new self(PricingAdjustmentKind::PercentageOff, percentageOff: $rate);
    }

    public static function fixedAmountOff(Money $amount): self
    {
        return new self(PricingAdjustmentKind::FixedAmountOff, fixedAmount: $amount);
    }

    public static function fixedPrice(Money $price): self
    {
        return new self(PricingAdjustmentKind::FixedPrice, fixedAmount: $price);
    }

    public function withAfterCycle(int $afterCycle, self $policy): self
    {
        return new self(
            kind: $this->kind,
            percentageOff: $this->percentageOff,
            fixedAmount: $this->fixedAmount,
            afterCycle: $afterCycle,
            afterCyclePolicy: $policy,
        );
    }

    public function applyTo(Money $baseUnitPrice, int $cycle = 1, RoundingMode $rounding = RoundingMode::HalfUp): Money
    {
        if ($cycle < 1) {
            throw new InvalidCommand('cycle', 'must be at least 1', 'sellable.invalid_cycle');
        }

        if ($baseUnitPrice->isNegative()) {
            throw new InvalidCommand('baseUnitPrice', 'must not be negative', 'sellable.invalid_pricing_policy');
        }

        if ($this->afterCycle !== null && $this->afterCyclePolicy !== null && $cycle > $this->afterCycle) {
            return $this->afterCyclePolicy->applySingle($baseUnitPrice, $rounding);
        }

        return $this->applySingle($baseUnitPrice, $rounding);
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->percentageOff?->basisPoints === $other->percentageOff?->basisPoints
            && (($this->fixedAmount === null && $other->fixedAmount === null)
                || ($this->fixedAmount !== null && $other->fixedAmount !== null && $this->fixedAmount->equals($other->fixedAmount)))
            && $this->afterCycle === $other->afterCycle
            && (($this->afterCyclePolicy === null && $other->afterCyclePolicy === null)
                || ($this->afterCyclePolicy !== null && $other->afterCyclePolicy !== null && $this->afterCyclePolicy->equals($other->afterCyclePolicy)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'percentage_off_bp' => $this->percentageOff?->basisPoints,
            'fixed_amount_minor' => $this->fixedAmount?->amountMinor,
            'fixed_amount_currency' => $this->fixedAmount?->currency->code,
            'after_cycle' => $this->afterCycle,
            'after_cycle_policy' => $this->afterCyclePolicy?->toArray(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $rawKind = $data['kind'] ?? PricingAdjustmentKind::None->value;
        if (! is_string($rawKind)) {
            throw new InvalidCommand('pricingPolicy.kind', 'must be a string', 'sellable.invalid_pricing_policy');
        }

        $kind = PricingAdjustmentKind::tryFrom($rawKind)
            ?? throw new InvalidCommand('pricingPolicy.kind', "unknown pricing adjustment kind '{$rawKind}'", 'sellable.invalid_pricing_policy');

        $percentageOff = isset($data['percentage_off_bp']) && is_int($data['percentage_off_bp'])
            ? Rate::bp($data['percentage_off_bp'])
            : null;

        $fixedAmount = null;
        if (isset($data['fixed_amount_minor'], $data['fixed_amount_currency'])
            && is_int($data['fixed_amount_minor'])
            && is_string($data['fixed_amount_currency'])) {
            $fixedAmount = Money::ofMinor($data['fixed_amount_minor'], $data['fixed_amount_currency']);
        }

        $afterCycle = isset($data['after_cycle']) && is_int($data['after_cycle']) ? $data['after_cycle'] : null;
        $afterCyclePolicy = null;
        if (isset($data['after_cycle_policy']) && is_array($data['after_cycle_policy'])) {
            /** @var array<string, mixed> $nested */
            $nested = $data['after_cycle_policy'];
            $afterCyclePolicy = self::fromArray($nested);
        }

        return new self(
            kind: $kind,
            percentageOff: $percentageOff,
            fixedAmount: $fixedAmount,
            afterCycle: $afterCycle,
            afterCyclePolicy: $afterCyclePolicy,
        );
    }

    private function applySingle(Money $baseUnitPrice, RoundingMode $rounding): Money
    {
        return match ($this->kind) {
            PricingAdjustmentKind::None => $baseUnitPrice,
            PricingAdjustmentKind::PercentageOff => $baseUnitPrice->minus(
                $baseUnitPrice->percentage($this->percentageOff ?? Rate::bp(0), $rounding),
            ),
            PricingAdjustmentKind::FixedAmountOff => $this->subtractCappedAtZero($baseUnitPrice, $this->fixedAmount),
            PricingAdjustmentKind::FixedPrice => $this->assertSameCurrency($baseUnitPrice, $this->fixedAmount),
        };
    }

    private function subtractCappedAtZero(Money $base, ?Money $deduction): Money
    {
        $amount = $this->assertSameCurrency($base, $deduction);

        if ($amount->compare($base) >= 0) {
            return Money::zero($base->currency);
        }

        return $base->minus($amount);
    }

    private function assertSameCurrency(Money $base, ?Money $other): Money
    {
        if ($other === null) {
            return $base;
        }

        if (! $base->currency->equals($other->currency)) {
            throw new InvalidCommand(
                'pricingPolicy',
                "currency {$other->currency->code} does not match {$base->currency->code}",
                'sellable.currency_mismatch',
            );
        }

        return $other;
    }

    private function assertNone(?Rate $percentageOff, ?Money $fixedAmount): void
    {
        if ($percentageOff !== null || $fixedAmount !== null) {
            throw new InvalidCommand(
                'pricingPolicy',
                'percentageOff and fixedAmount must be null when kind is none',
                'sellable.invalid_pricing_policy',
            );
        }
    }

    private function assertPercentage(?Rate $percentageOff, ?Money $fixedAmount): void
    {
        if ($percentageOff === null || $fixedAmount !== null) {
            throw new InvalidCommand(
                'percentageOff',
                'percentageOff is required and fixedAmount must be null when kind is percentage_off',
                'sellable.invalid_pricing_policy',
            );
        }

        if ($percentageOff->basisPoints > 10_000) {
            throw new InvalidCommand('percentageOff', 'must not exceed 100% (10000 basis points)', 'sellable.invalid_pricing_policy');
        }
    }

    private function assertFixedAmount(?Rate $percentageOff, ?Money $fixedAmount): void
    {
        if ($fixedAmount === null || $percentageOff !== null) {
            throw new InvalidCommand(
                'fixedAmount',
                'fixedAmount is required and percentageOff must be null for fixed pricing policies',
                'sellable.invalid_pricing_policy',
            );
        }

        if ($fixedAmount->isNegative()) {
            throw new InvalidCommand('fixedAmount', 'must not be negative', 'sellable.invalid_pricing_policy');
        }
    }
}
