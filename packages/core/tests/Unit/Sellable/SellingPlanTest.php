<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Sellable\BalanceDueTrigger;
use Commerz\Sellable\BillingPolicy;
use Commerz\Sellable\DeliveryPolicy;
use Commerz\Sellable\PricingAdjustmentKind;
use Commerz\Sellable\PricingPolicy;
use Commerz\Sellable\SellingPlan;
use Commerz\Sellable\SellingPlanInterval;

it('models SellingPlan::deposit with 20% due now and 80% remaining before shipment and round-trips via array', function (): void {
    $plan = SellingPlan::deposit(
        id: 'preorder-20-80',
        name: 'Pre-order: 20% Deposit Now, 80% Before Shipment',
        depositRate: Rate::percent('20'),
        balanceDueTrigger: BalanceDueTrigger::BeforeShipment,
        requiresSavedPaymentMethod: true,
    );

    $total = Money::of('250.00', 'EUR');

    expect($plan->billingPolicy)->toBe(BillingPolicy::Deposit)
        ->and($plan->isDeposit())->toBeTrue()
        ->and($plan->isRecurring())->toBeFalse()
        ->and($plan->isDeferred())->toBeFalse()
        ->and($plan->requiresSavedPaymentMethod)->toBeTrue()
        ->and($plan->balanceDueTrigger)->toBe(BalanceDueTrigger::BeforeShipment)
        ->and($plan->computeDueNow($total))->toEqual(Money::of('50.00', 'EUR'))
        ->and($plan->computeRemainingBalance($total))->toEqual(Money::of('200.00', 'EUR'))
        ->and(SellingPlan::fromArray($plan->toArray())->equals($plan))->toBeTrue();
});

it('supports fixed-amount deposits, after-checkout due dates, and fixed due dates', function (): void {
    $afterDays = SellingPlan::deposit(
        id: 'layaway-fixed',
        name: 'Layaway $30 Deposit, Balance in 30 Days',
        depositFixedAmount: Money::of('30.00', 'USD'),
        balanceDueTrigger: BalanceDueTrigger::AfterCheckoutDays,
        balanceDueDays: 30,
    );

    $checkoutAt = new DateTimeImmutable('2026-11-26T10:00:00Z');
    expect($afterDays->computeDueNow(Money::of('100.00', 'USD')))->toEqual(Money::of('30.00', 'USD'))
        ->and($afterDays->computeRemainingBalance(Money::of('100.00', 'USD')))->toEqual(Money::of('70.00', 'USD'))
        ->and($afterDays->computeDueNow(Money::of('20.00', 'USD')))->toEqual(Money::of('20.00', 'USD'))
        ->and($afterDays->balanceDueDate($checkoutAt)?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-12-26T10:00:00Z');

    $fixedDue = new DateTimeImmutable('2027-01-15T00:00:00Z');
    $fixedPlan = SellingPlan::deposit(
        id: 'preorder-fixed-date',
        name: 'Pre-order Fixed Release Balance',
        depositRate: Rate::percent('25'),
        balanceDueTrigger: BalanceDueTrigger::FixedDate,
        balanceDueAt: $fixedDue,
        deliveryPolicy: DeliveryPolicy::onDate(new DateTimeImmutable('2027-01-20T00:00:00Z')),
    );

    expect($fixedPlan->balanceDueDate($checkoutAt)?->format('Y-m-d\TH:i:s\Z'))->toBe('2027-01-15T00:00:00Z')
        ->and(SellingPlan::fromArray($fixedPlan->toArray())->equals($fixedPlan))->toBeTrue();
});

it('models SellingPlan::recurring with cycle-aware PricingPolicy and cadence calculation', function (): void {
    $pricing = PricingPolicy::percentageOff(Rate::percent('20'))
        ->withAfterCycle(1, PricingPolicy::percentageOff(Rate::percent('10')));

    $plan = SellingPlan::recurring(
        id: 'sub-monthly-intro',
        name: 'Subscribe & Save (20% First Month, 10% Ongoing)',
        interval: SellingPlanInterval::Month,
        intervalCount: 1,
        pricingPolicy: $pricing,
        minCycles: 2,
        maxCycles: 12,
    );

    $base = Money::of('100.00', 'USD');
    $anchor = new DateTimeImmutable('2026-11-26T12:00:00Z');

    expect($plan->billingPolicy)->toBe(BillingPolicy::Recurring)
        ->and($plan->isRecurring())->toBeTrue()
        ->and($plan->requiresSavedPaymentMethod)->toBeTrue()
        ->and($plan->deliveryPolicy->isRecurring())->toBeTrue()
        ->and($plan->pricingPolicy->kind)->toBe(PricingAdjustmentKind::PercentageOff)
        ->and($plan->unitPriceForCycle($base, 1))->toEqual(Money::of('80.00', 'USD'))
        ->and($plan->unitPriceForCycle($base, 2))->toEqual(Money::of('90.00', 'USD'))
        ->and($plan->unitPriceForCycle($base, 5))->toEqual(Money::of('90.00', 'USD'))
        ->and($plan->computeDueNow(Money::of('80.00', 'USD')))->toEqual(Money::of('80.00', 'USD'))
        ->and($plan->computeRemainingBalance(Money::of('80.00', 'USD')))->toEqual(Money::zero('USD'))
        ->and($plan->nextBillingAt($anchor, 1)->format('Y-m-d\TH:i:s\Z'))->toBe('2026-12-26T12:00:00Z')
        ->and($plan->nextBillingAt($anchor, 2)->format('Y-m-d\TH:i:s\Z'))->toBe('2027-01-26T12:00:00Z')
        ->and(SellingPlan::fromArray($plan->toArray())->equals($plan))->toBeTrue();
});

it('models SellingPlan::deferred (Try-Before-You-Buy) with zero due now and full balance due after trial', function (): void {
    $plan = SellingPlan::deferred(
        id: 'tbyb-14d',
        name: 'Try Before You Buy (14 Days)',
        balanceDueTrigger: BalanceDueTrigger::AfterCheckoutDays,
        balanceDueDays: 14,
    );

    $total = Money::of('180.00', 'EUR');
    $checkoutAt = new DateTimeImmutable('2026-11-01T08:00:00Z');

    expect($plan->billingPolicy)->toBe(BillingPolicy::Deferred)
        ->and($plan->isDeferred())->toBeTrue()
        ->and($plan->requiresSavedPaymentMethod)->toBeTrue()
        ->and($plan->computeDueNow($total))->toEqual(Money::zero('EUR'))
        ->and($plan->computeRemainingBalance($total))->toEqual(Money::of('180.00', 'EUR'))
        ->and($plan->balanceDueDate($checkoutAt)?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-11-15T08:00:00Z')
        ->and(SellingPlan::fromArray($plan->toArray())->equals($plan))->toBeTrue();
});

it('enforces SellingPlan, PricingPolicy, and DeliveryPolicy domain invariants', function (): void {
    expect(fn () => SellingPlan::recurring(
        id: 'bad-recurring',
        name: 'No Saved Card',
        requiresSavedPaymentMethod: false,
    ))->toThrow(InvalidCommand::class)
        ->and(fn () => SellingPlan::deposit(
            id: 'bad-deposit-100',
            name: '100% Deposit',
            depositRate: Rate::percent('100'),
        ))->toThrow(InvalidCommand::class)
        ->and(fn () => SellingPlan::deposit(
            id: 'bad-deposit-both',
            name: 'Both Rate and Fixed',
            depositRate: Rate::percent('20'),
            depositFixedAmount: Money::of('10.00', 'USD'),
        ))->toThrow(InvalidCommand::class)
        ->and(fn () => PricingPolicy::percentageOff(Rate::bp(10_001)))->toThrow(InvalidCommand::class)
        ->and(fn () => new DeliveryPolicy(
            interval: SellingPlanInterval::Week,
            intervalCount: 1,
            fulfillAfterDays: 3,
        ))->toThrow(InvalidCommand::class);
});
