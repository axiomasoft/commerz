<?php

declare(strict_types=1);

use Commerz\B2b\PaymentTerms\Data\DepositMilestone;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\B2b\PaymentTerms\Data\SellingPlanSummary;
use Commerz\B2b\PaymentTerms\Enums\PaymentTermsType;
use Commerz\B2b\PaymentTerms\Enums\SellingPlanBillingKind;
use Commerz\B2b\PaymentTerms\Exceptions\InvalidPaymentTerms;
use Commerz\Money\Money;
use Commerz\Money\Rate;

enum TestBillingPolicyStub: string
{
    case Recurring = 'recurring';
    case Deposit = 'deposit';
}

it('calculates Net15, Net30, Net60, and multi-milestone deposit schedules with exact minor-unit allocation', function (): void {
    $issuedAt = new DateTimeImmutable('2026-11-26T12:00:00Z');
    $total = Money::of('1000.00', 'USD');

    $net15 = PaymentTerms::net15()->schedule($total, $issuedAt);
    expect($net15->dueNowAmount)->toEqual(Money::zero('USD'))
        ->and($net15->deferredAmount)->toEqual(Money::of('1000.00', 'USD'))
        ->and($net15->finalDueAt)->toEqual(new DateTimeImmutable('2026-12-11T12:00:00Z'));

    $net30 = PaymentTerms::net30()->schedule($total, $issuedAt);
    expect($net30->dueNowAmount)->toEqual(Money::zero('USD'))
        ->and($net30->deferredAmount)->toEqual(Money::of('1000.00', 'USD'))
        ->and($net30->finalDueAt)->toEqual(new DateTimeImmutable('2026-12-26T12:00:00Z'));

    $net60 = PaymentTerms::net60()->schedule($total, $issuedAt);
    expect($net60->dueNowAmount)->toEqual(Money::zero('USD'))
        ->and($net60->deferredAmount)->toEqual(Money::of('1000.00', 'USD'))
        ->and($net60->finalDueAt)->toEqual(new DateTimeImmutable('2027-01-25T12:00:00Z'));

    // 3-way split on $100.00 (3334 + 3333 + 3333 bp = 10000 bp) on an odd total $100.01
    $depositTerms = PaymentTerms::depositSchedule('dep_3way', [
        new DepositMilestone(Rate::bp(2000), 0, '20% Upfront Deposit'),
        new DepositMilestone(Rate::bp(3000), 15, '30% Net 15'),
        new DepositMilestone(Rate::bp(5000), 45, '50% Net 45'),
    ]);

    $schedule = $depositTerms->schedule(Money::of('100.01', 'USD'), $issuedAt);
    $sum = Money::zero('USD');
    foreach ($schedule->installments as $inst) {
        $sum = $sum->plus($inst->amount);
    }

    expect($depositTerms->type)->toBe(PaymentTermsType::DepositSchedule)
        ->and($depositTerms->netDays)->toBe(45)
        ->and($sum)->toEqual(Money::of('100.01', 'USD'))
        ->and($schedule->dueNowAmount->plus($schedule->deferredAmount))->toEqual(Money::of('100.01', 'USD'))
        ->and($schedule->installments[0]->isDeposit)->toBeTrue()
        ->and($schedule->installments[1]->isDeposit)->toBeFalse()
        ->and($schedule->finalDueAt)->toEqual(new DateTimeImmutable('2027-01-10T12:00:00Z'));
});

it('rejects payment terms whose milestones do not sum to 10000 bp or have fewer than 2 deposit milestones', function (): void {
    expect(static fn (): PaymentTerms => PaymentTerms::depositSchedule('bad_sum', [
        new DepositMilestone(Rate::bp(2000), 0, 'Deposit'),
        new DepositMilestone(Rate::bp(7000), 30, 'Balance'),
    ]))->toThrow(InvalidPaymentTerms::class);

    expect(static fn (): PaymentTerms => PaymentTerms::depositSchedule('single_milestone', [
        new DepositMilestone(Rate::bp(10_000), 0, 'All Upfront'),
    ]))->toThrow(InvalidPaymentTerms::class);
});

it('extracts recurring and non-recurring SellingPlan billing kinds from explicit summaries, objects, and attributes', function (): void {
    $recurringObj = new readonly class('monthly-auto-ship', TestBillingPolicyStub::Recurring)
    {
        public function __construct(
            public string $code,
            public TestBillingPolicyStub $billingPolicy,
        ) {}
    };

    $depositObj = new readonly class('preorder-20pct', TestBillingPolicyStub::Deposit)
    {
        public function __construct(
            public string $code,
            public TestBillingPolicyStub $billingPolicy,
        ) {}
    };

    $fromRecurring = SellingPlanSummary::from($recurringObj);
    $fromDeposit = SellingPlanSummary::from($depositObj);
    $fromAttrs = SellingPlanSummary::fromSellableAttributes([
        'selling_plan_billing_policy' => 'recurring',
        'selling_plan_code' => 'sub-weekly',
    ]);

    expect($fromRecurring->isRecurring())->toBeTrue()
        ->and($fromRecurring->code)->toBe('monthly-auto-ship')
        ->and($fromDeposit->isRecurring())->toBeFalse()
        ->and($fromDeposit->billingKind)->toBe(SellingPlanBillingKind::Deposit)
        ->and($fromAttrs?->isRecurring())->toBeTrue()
        ->and($fromAttrs?->code)->toBe('sub-weekly');
});
