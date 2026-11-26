<?php

declare(strict_types=1);

namespace Commerz\Sellable;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Money\RoundingMode;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

/**
 * Universal selling plan describing how any sellable is priced, billed, and delivered:
 * - {@see BillingPolicy::Recurring}: subscriptions (Subscribe & Save, replenishment, memberships)
 * - {@see BillingPolicy::Deposit}: partial upfront payment (pre-orders, layaway, custom manufacturing, e.g. 20% now / 80% before shipment)
 * - {@see BillingPolicy::Deferred}: Try-Before-You-Buy (0 due at checkout, full balance collected after trial or on fulfillment)
 */
#[Api(since: '0.1')]
final readonly class SellingPlan
{
    public ?DateTimeImmutable $balanceDueAt;

    public function __construct(
        public string $id,
        public string $name,
        public BillingPolicy $billingPolicy,
        public PricingPolicy $pricingPolicy,
        public DeliveryPolicy $deliveryPolicy,
        public bool $requiresSavedPaymentMethod,
        public ?SellingPlanInterval $billingInterval = null,
        public ?int $billingIntervalCount = null,
        public ?int $minCycles = null,
        public ?int $maxCycles = null,
        public ?Rate $depositRate = null,
        public ?Money $depositFixedAmount = null,
        public ?BalanceDueTrigger $balanceDueTrigger = null,
        public ?int $balanceDueDays = null,
        ?DateTimeImmutable $balanceDueAt = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/', $id) !== 1) {
            throw new InvalidCommand('id', "'{$id}' must match [A-Za-z0-9_.:-]{1,64}", 'sellable.invalid_selling_plan');
        }

        if (trim($name) === '' || strlen($name) > 191) {
            throw new InvalidCommand('name', 'must be 1..191 non-blank bytes', 'sellable.invalid_selling_plan');
        }

        $normalizedDueAt = $balanceDueAt?->setTimezone(new DateTimeZone('UTC'));
        $this->balanceDueAt = $normalizedDueAt;

        match ($billingPolicy) {
            BillingPolicy::Recurring => $this->assertRecurring(),
            BillingPolicy::Deposit => $this->assertDeposit(),
            BillingPolicy::Deferred => $this->assertDeferred(),
        };
    }

    public static function recurring(
        string $id,
        string $name,
        SellingPlanInterval $interval = SellingPlanInterval::Month,
        int $intervalCount = 1,
        ?PricingPolicy $pricingPolicy = null,
        ?DeliveryPolicy $deliveryPolicy = null,
        ?int $minCycles = null,
        ?int $maxCycles = null,
        bool $requiresSavedPaymentMethod = true,
    ): self {
        return new self(
            id: $id,
            name: $name,
            billingPolicy: BillingPolicy::Recurring,
            pricingPolicy: $pricingPolicy ?? PricingPolicy::none(),
            deliveryPolicy: $deliveryPolicy ?? DeliveryPolicy::every($interval, $intervalCount),
            requiresSavedPaymentMethod: $requiresSavedPaymentMethod,
            billingInterval: $interval,
            billingIntervalCount: $intervalCount,
            minCycles: $minCycles,
            maxCycles: $maxCycles,
        );
    }

    public static function deposit(
        string $id,
        string $name,
        ?Rate $depositRate = null,
        ?Money $depositFixedAmount = null,
        BalanceDueTrigger $balanceDueTrigger = BalanceDueTrigger::BeforeShipment,
        ?int $balanceDueDays = null,
        ?DateTimeImmutable $balanceDueAt = null,
        ?PricingPolicy $pricingPolicy = null,
        ?DeliveryPolicy $deliveryPolicy = null,
        bool $requiresSavedPaymentMethod = false,
    ): self {
        return new self(
            id: $id,
            name: $name,
            billingPolicy: BillingPolicy::Deposit,
            pricingPolicy: $pricingPolicy ?? PricingPolicy::none(),
            deliveryPolicy: $deliveryPolicy ?? DeliveryPolicy::immediate(),
            requiresSavedPaymentMethod: $requiresSavedPaymentMethod,
            depositRate: $depositRate,
            depositFixedAmount: $depositFixedAmount,
            balanceDueTrigger: $balanceDueTrigger,
            balanceDueDays: $balanceDueDays,
            balanceDueAt: $balanceDueAt,
        );
    }

    public static function deferred(
        string $id,
        string $name,
        BalanceDueTrigger $balanceDueTrigger = BalanceDueTrigger::AfterCheckoutDays,
        ?int $balanceDueDays = 14,
        ?DateTimeImmutable $balanceDueAt = null,
        ?PricingPolicy $pricingPolicy = null,
        ?DeliveryPolicy $deliveryPolicy = null,
        bool $requiresSavedPaymentMethod = true,
    ): self {
        $resolvedDays = $balanceDueTrigger === BalanceDueTrigger::AfterCheckoutDays ? $balanceDueDays : null;

        return new self(
            id: $id,
            name: $name,
            billingPolicy: BillingPolicy::Deferred,
            pricingPolicy: $pricingPolicy ?? PricingPolicy::none(),
            deliveryPolicy: $deliveryPolicy ?? DeliveryPolicy::immediate(),
            requiresSavedPaymentMethod: $requiresSavedPaymentMethod,
            balanceDueTrigger: $balanceDueTrigger,
            balanceDueDays: $resolvedDays,
            balanceDueAt: $balanceDueAt,
        );
    }

    public function isRecurring(): bool
    {
        return $this->billingPolicy === BillingPolicy::Recurring;
    }

    public function isDeposit(): bool
    {
        return $this->billingPolicy === BillingPolicy::Deposit;
    }

    public function isDeferred(): bool
    {
        return $this->billingPolicy === BillingPolicy::Deferred;
    }

    public function unitPriceForCycle(Money $baseUnitPrice, int $cycle = 1, RoundingMode $rounding = RoundingMode::HalfUp): Money
    {
        return $this->pricingPolicy->applyTo($baseUnitPrice, $cycle, $rounding);
    }

    /**
     * Computes the portion of `$total` payable immediately at checkout.
     */
    public function computeDueNow(Money $total, RoundingMode $rounding = RoundingMode::HalfUp): Money
    {
        if ($total->isNegative()) {
            throw new InvalidCommand('total', 'must not be negative', 'sellable.invalid_amount');
        }

        if ($total->isZero()) {
            return $total;
        }

        return match ($this->billingPolicy) {
            BillingPolicy::Recurring => $total,
            BillingPolicy::Deferred => Money::zero($total->currency),
            BillingPolicy::Deposit => $this->computeDepositDueNow($total, $rounding),
        };
    }

    /**
     * Computes the deferred balance of `$total` (`total - dueNow`), guaranteed non-negative and exact to the minor unit.
     */
    public function computeRemainingBalance(Money $total, RoundingMode $rounding = RoundingMode::HalfUp): Money
    {
        return $total->minus($this->computeDueNow($total, $rounding));
    }

    public function nextBillingAt(DateTimeImmutable $anchor, int $cycles = 1): DateTimeImmutable
    {
        if ($this->billingPolicy !== BillingPolicy::Recurring || $this->billingInterval === null || $this->billingIntervalCount === null) {
            throw new InvalidCommand('billingPolicy', 'nextBillingAt is only defined for recurring selling plans', 'sellable.not_recurring');
        }

        if ($cycles < 1) {
            throw new InvalidCommand('cycles', 'must be at least 1', 'sellable.invalid_cycle');
        }

        return $this->billingInterval->addTo($anchor, $this->billingIntervalCount * $cycles);
    }

    public function balanceDueDate(DateTimeImmutable $checkoutAt): ?DateTimeImmutable
    {
        $utc = $checkoutAt->setTimezone(new DateTimeZone('UTC'));

        if ($this->balanceDueTrigger === BalanceDueTrigger::FixedDate) {
            return $this->balanceDueAt;
        }

        if ($this->balanceDueTrigger === BalanceDueTrigger::AfterCheckoutDays && $this->balanceDueDays !== null) {
            return $utc->add(new DateInterval("P{$this->balanceDueDays}D"));
        }

        return null;
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id
            && $this->name === $other->name
            && $this->billingPolicy === $other->billingPolicy
            && $this->pricingPolicy->equals($other->pricingPolicy)
            && $this->deliveryPolicy->equals($other->deliveryPolicy)
            && $this->requiresSavedPaymentMethod === $other->requiresSavedPaymentMethod
            && $this->billingInterval === $other->billingInterval
            && $this->billingIntervalCount === $other->billingIntervalCount
            && $this->minCycles === $other->minCycles
            && $this->maxCycles === $other->maxCycles
            && $this->depositRate?->basisPoints === $other->depositRate?->basisPoints
            && (($this->depositFixedAmount === null && $other->depositFixedAmount === null)
                || ($this->depositFixedAmount !== null && $other->depositFixedAmount !== null && $this->depositFixedAmount->equals($other->depositFixedAmount)))
            && $this->balanceDueTrigger === $other->balanceDueTrigger
            && $this->balanceDueDays === $other->balanceDueDays
            && $this->balanceDueAt?->format('Y-m-d\TH:i:s.uP') === $other->balanceDueAt?->format('Y-m-d\TH:i:s.uP');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'billing_policy' => $this->billingPolicy->value,
            'pricing_policy' => $this->pricingPolicy->toArray(),
            'delivery_policy' => $this->deliveryPolicy->toArray(),
            'requires_saved_payment_method' => $this->requiresSavedPaymentMethod,
            'billing_interval' => $this->billingInterval?->value,
            'billing_interval_count' => $this->billingIntervalCount,
            'min_cycles' => $this->minCycles,
            'max_cycles' => $this->maxCycles,
            'deposit_rate_bp' => $this->depositRate?->basisPoints,
            'deposit_fixed_amount_minor' => $this->depositFixedAmount?->amountMinor,
            'deposit_fixed_amount_currency' => $this->depositFixedAmount?->currency->code,
            'balance_due_trigger' => $this->balanceDueTrigger?->value,
            'balance_due_days' => $this->balanceDueDays,
            'balance_due_at' => $this->balanceDueAt?->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $id = isset($data['id']) && is_string($data['id']) ? $data['id'] : '';
        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        $rawPolicy = isset($data['billing_policy']) && is_string($data['billing_policy']) ? $data['billing_policy'] : '';

        $billingPolicy = BillingPolicy::tryFrom($rawPolicy)
            ?? throw new InvalidCommand('billingPolicy', "unknown billing policy '{$rawPolicy}'", 'sellable.invalid_selling_plan');

        /** @var array<string, mixed> $rawPricing */
        $rawPricing = isset($data['pricing_policy']) && is_array($data['pricing_policy']) ? $data['pricing_policy'] : [];
        /** @var array<string, mixed> $rawDelivery */
        $rawDelivery = isset($data['delivery_policy']) && is_array($data['delivery_policy']) ? $data['delivery_policy'] : [];

        $requiresSaved = isset($data['requires_saved_payment_method']) && is_bool($data['requires_saved_payment_method'])
            ? $data['requires_saved_payment_method']
            : ($billingPolicy !== BillingPolicy::Deposit);

        $billingInterval = isset($data['billing_interval']) && is_string($data['billing_interval'])
            ? (SellingPlanInterval::tryFrom($data['billing_interval'])
                ?? throw new InvalidCommand('billingInterval', "unknown interval '{$data['billing_interval']}'", 'sellable.invalid_selling_plan'))
            : null;

        $billingIntervalCount = isset($data['billing_interval_count']) && is_int($data['billing_interval_count'])
            ? $data['billing_interval_count']
            : null;

        $minCycles = isset($data['min_cycles']) && is_int($data['min_cycles']) ? $data['min_cycles'] : null;
        $maxCycles = isset($data['max_cycles']) && is_int($data['max_cycles']) ? $data['max_cycles'] : null;
        $depositRate = isset($data['deposit_rate_bp']) && is_int($data['deposit_rate_bp'])
            ? Rate::bp($data['deposit_rate_bp'])
            : null;

        $depositFixedAmount = null;
        if (isset($data['deposit_fixed_amount_minor'], $data['deposit_fixed_amount_currency'])
            && is_int($data['deposit_fixed_amount_minor'])
            && is_string($data['deposit_fixed_amount_currency'])) {
            $depositFixedAmount = Money::ofMinor($data['deposit_fixed_amount_minor'], $data['deposit_fixed_amount_currency']);
        }

        $balanceDueTrigger = isset($data['balance_due_trigger']) && is_string($data['balance_due_trigger'])
            ? (BalanceDueTrigger::tryFrom($data['balance_due_trigger'])
                ?? throw new InvalidCommand('balanceDueTrigger', "unknown trigger '{$data['balance_due_trigger']}'", 'sellable.invalid_selling_plan'))
            : null;

        $balanceDueDays = isset($data['balance_due_days']) && is_int($data['balance_due_days']) ? $data['balance_due_days'] : null;
        $balanceDueAt = isset($data['balance_due_at']) && is_string($data['balance_due_at'])
            ? new DateTimeImmutable($data['balance_due_at'])
            : null;

        return new self(
            id: $id,
            name: $name,
            billingPolicy: $billingPolicy,
            pricingPolicy: PricingPolicy::fromArray($rawPricing),
            deliveryPolicy: DeliveryPolicy::fromArray($rawDelivery),
            requiresSavedPaymentMethod: $requiresSaved,
            billingInterval: $billingInterval,
            billingIntervalCount: $billingIntervalCount,
            minCycles: $minCycles,
            maxCycles: $maxCycles,
            depositRate: $depositRate,
            depositFixedAmount: $depositFixedAmount,
            balanceDueTrigger: $balanceDueTrigger,
            balanceDueDays: $balanceDueDays,
            balanceDueAt: $balanceDueAt,
        );
    }

    private function computeDepositDueNow(Money $total, RoundingMode $rounding): Money
    {
        if ($this->depositRate !== null) {
            $share = $total->percentage($this->depositRate, $rounding);

            return $share->compare($total) > 0 ? $total : $share;
        }

        if ($this->depositFixedAmount !== null) {
            if (! $this->depositFixedAmount->currency->equals($total->currency)) {
                throw new InvalidCommand(
                    'depositFixedAmount',
                    "currency {$this->depositFixedAmount->currency->code} differs from {$total->currency->code}",
                    'sellable.currency_mismatch',
                );
            }

            return $this->depositFixedAmount->compare($total) > 0 ? $total : $this->depositFixedAmount;
        }

        return $total;
    }

    private function assertRecurring(): void
    {
        if ($this->billingInterval === null || $this->billingIntervalCount === null || $this->billingIntervalCount < 1) {
            throw new InvalidCommand(
                'billingInterval',
                'recurring selling plans require billingInterval and billingIntervalCount >= 1',
                'sellable.invalid_selling_plan',
            );
        }

        if (! $this->requiresSavedPaymentMethod) {
            throw new InvalidCommand(
                'requiresSavedPaymentMethod',
                'recurring selling plans must require a saved payment method',
                'sellable.invalid_selling_plan',
            );
        }

        if ($this->depositRate !== null || $this->depositFixedAmount !== null || $this->balanceDueTrigger !== null || $this->balanceDueDays !== null || $this->balanceDueAt !== null) {
            throw new InvalidCommand(
                'billingPolicy',
                'recurring selling plans cannot declare deposit or deferred balance fields',
                'sellable.invalid_selling_plan',
            );
        }

        if ($this->minCycles !== null && $this->minCycles < 1) {
            throw new InvalidCommand('minCycles', 'must be at least 1', 'sellable.invalid_selling_plan');
        }

        if ($this->maxCycles !== null && $this->maxCycles < ($this->minCycles ?? 1)) {
            throw new InvalidCommand('maxCycles', 'must be greater than or equal to minCycles (and at least 1)', 'sellable.invalid_selling_plan');
        }
    }

    private function assertDeposit(): void
    {
        if (($this->depositRate === null) === ($this->depositFixedAmount === null)) {
            throw new InvalidCommand(
                'deposit',
                'deposit selling plans require exactly one of depositRate or depositFixedAmount',
                'sellable.invalid_selling_plan',
            );
        }

        if ($this->depositRate !== null && ($this->depositRate->basisPoints < 1 || $this->depositRate->basisPoints >= 10_000)) {
            throw new InvalidCommand(
                'depositRate',
                'depositRate must be between 1 and 9999 basis points (0.01%..99.99%)',
                'sellable.invalid_selling_plan',
            );
        }

        if ($this->depositFixedAmount !== null && ! $this->depositFixedAmount->isPositive()) {
            throw new InvalidCommand('depositFixedAmount', 'must be greater than zero', 'sellable.invalid_selling_plan');
        }

        if ($this->billingInterval !== null || $this->billingIntervalCount !== null || $this->minCycles !== null || $this->maxCycles !== null) {
            throw new InvalidCommand(
                'billingPolicy',
                'deposit selling plans cannot declare recurring cycle fields',
                'sellable.invalid_selling_plan',
            );
        }

        $this->assertBalanceTrigger();
    }

    private function assertDeferred(): void
    {
        if (! $this->requiresSavedPaymentMethod) {
            throw new InvalidCommand(
                'requiresSavedPaymentMethod',
                'deferred selling plans must require a saved payment method',
                'sellable.invalid_selling_plan',
            );
        }

        if ($this->depositRate !== null || $this->depositFixedAmount !== null) {
            throw new InvalidCommand(
                'billingPolicy',
                'deferred selling plans cannot declare deposit amounts',
                'sellable.invalid_selling_plan',
            );
        }

        if ($this->billingInterval !== null || $this->billingIntervalCount !== null || $this->minCycles !== null || $this->maxCycles !== null) {
            throw new InvalidCommand(
                'billingPolicy',
                'deferred selling plans cannot declare recurring cycle fields',
                'sellable.invalid_selling_plan',
            );
        }

        $this->assertBalanceTrigger();
    }

    private function assertBalanceTrigger(): void
    {
        if ($this->balanceDueTrigger === null) {
            throw new InvalidCommand(
                'balanceDueTrigger',
                'balanceDueTrigger is required for deposit and deferred selling plans',
                'sellable.invalid_selling_plan',
            );
        }

        if ($this->balanceDueTrigger === BalanceDueTrigger::AfterCheckoutDays) {
            if ($this->balanceDueDays === null || $this->balanceDueDays < 1 || $this->balanceDueAt !== null) {
                throw new InvalidCommand(
                    'balanceDueDays',
                    'after_checkout_days trigger requires balanceDueDays >= 1 and null balanceDueAt',
                    'sellable.invalid_selling_plan',
                );
            }

            return;
        }

        if ($this->balanceDueTrigger === BalanceDueTrigger::FixedDate) {
            if ($this->balanceDueAt === null || $this->balanceDueDays !== null) {
                throw new InvalidCommand(
                    'balanceDueAt',
                    'fixed_date trigger requires balanceDueAt and null balanceDueDays',
                    'sellable.invalid_selling_plan',
                );
            }

            return;
        }

        if ($this->balanceDueDays !== null || $this->balanceDueAt !== null) {
            throw new InvalidCommand(
                'balanceDueTrigger',
                "{$this->balanceDueTrigger->value} trigger must not set balanceDueDays or balanceDueAt",
                'sellable.invalid_selling_plan',
            );
        }
    }
}
