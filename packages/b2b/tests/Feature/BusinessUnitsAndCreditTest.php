<?php

declare(strict_types=1);

use Commerz\B2b\BusinessUnits\Contracts\BusinessUnits;
use Commerz\B2b\BusinessUnits\Data\AssignAssociate;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitView;
use Commerz\B2b\BusinessUnits\Data\CreateBusinessUnit;
use Commerz\B2b\BusinessUnits\Enums\AssociateRole;
use Commerz\B2b\BusinessUnits\Exceptions\DuplicateBusinessUnitCode;
use Commerz\B2b\BusinessUnits\Exceptions\HierarchyDepthExceeded;
use Commerz\B2b\Configuration\B2bOptions;
use Commerz\B2b\Facades\B2b;
use Commerz\B2b\PaymentTerms\Data\CaptureCredit;
use Commerz\B2b\PaymentTerms\Data\ConfigureCreditAccount;
use Commerz\B2b\PaymentTerms\Data\DepositMilestone;
use Commerz\B2b\PaymentTerms\Data\PaymentTerms;
use Commerz\B2b\PaymentTerms\Data\ReleaseCredit;
use Commerz\B2b\PaymentTerms\Data\ReserveCredit;
use Commerz\B2b\PaymentTerms\Data\SellingPlanSummary;
use Commerz\B2b\PaymentTerms\Data\ValidateCreditTerms;
use Commerz\B2b\PaymentTerms\Enums\CreditAccountStatus;
use Commerz\B2b\PaymentTerms\Exceptions\CreditAccountFrozen;
use Commerz\B2b\PaymentTerms\Exceptions\CreditLimitExceeded;
use Commerz\B2b\PaymentTerms\Exceptions\RecurringSellingPlanIncompatibleWithPaymentTerms;
use Commerz\B2b\Tests\Support\B2bSqliteSchema;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;

beforeEach(function (): void {
    B2bSqliteSchema::boot('b2b_unit_sqlite');
});

it('manages BusinessUnit hierarchy, materialized paths, associate inheritance, and depth limits', function (): void {
    $hq = B2b::businessUnits()->create(new CreateBusinessUnit('acme-hq', 'Acme HQ', Currency::of('USD')));
    $emea = B2b::businessUnits()->create(new CreateBusinessUnit('acme-emea', 'Acme EMEA', Currency::of('USD'), parentId: $hq->id));
    $dach = B2b::businessUnits()->create(new CreateBusinessUnit('acme-dach', 'Acme DACH', Currency::of('EUR'), parentId: $emea->id));

    expect($hq->depth)->toBe(0)
        ->and($emea->depth)->toBe(1)
        ->and($dach->depth)->toBe(2)
        ->and($dach->rootId)->toEqual($hq->id)
        ->and(array_map(static fn (BusinessUnitView $u): string => $u->code, B2b::businessUnits()->ancestors($dach->id)))
        ->toBe(['acme-hq', 'acme-emea'])
        ->and(array_map(static fn (BusinessUnitView $u): string => $u->code, B2b::businessUnits()->descendants($hq->id)))
        ->toBe(['acme-emea', 'acme-dach']);

    expect(static fn () => B2b::businessUnits()->create(new CreateBusinessUnit('acme-hq', 'Duplicate', Currency::of('USD'))))
        ->toThrow(DuplicateBusinessUnitCode::class);

    $cfo = CustomerRef::of('user', 'cfo-1');
    $buyer = CustomerRef::of('user', 'buyer-dach');

    B2b::businessUnits()->assignAssociate(new AssignAssociate($hq->id, $cfo, AssociateRole::Admin));
    B2b::businessUnits()->assignAssociate(new AssignAssociate($dach->id, $buyer, AssociateRole::Buyer, perOrderSpendingLimit: Money::of('2500.00', 'EUR')));

    $resolvedCfo = B2b::businessUnits()->resolveAssociate($dach->id, $cfo);
    $resolvedBuyer = B2b::businessUnits()->resolveAssociate($dach->id, $buyer);

    expect($resolvedCfo)->not->toBeNull()
        ->and($resolvedCfo?->inherited)->toBeTrue()
        ->and($resolvedCfo?->role)->toBe(AssociateRole::Admin)
        ->and($resolvedBuyer?->inherited)->toBeFalse()
        ->and($resolvedBuyer?->exceedsPerOrderLimit(Money::of('2500.01', 'EUR')))->toBeTrue()
        ->and($resolvedBuyer?->exceedsPerOrderLimit(Money::of('2500.00', 'EUR')))->toBeFalse();
});

it('enforces max_hierarchy_depth on nested BusinessUnits', function (): void {
    config()->set('commerz-b2b.max_hierarchy_depth', 1);
    app()->forgetInstance(B2bOptions::class);
    app()->forgetInstance(BusinessUnits::class);
    app()->forgetInstance(Commerz\B2b\B2b::class);

    $root = B2b::businessUnits()->create(new CreateBusinessUnit('root-co', 'Root Co', Currency::of('USD')));
    $child = B2b::businessUnits()->create(new CreateBusinessUnit('div-1', 'Division 1', Currency::of('USD'), parentId: $root->id));

    expect(static fn () => B2b::businessUnits()->create(new CreateBusinessUnit('dept-2', 'Dept 2', Currency::of('USD'), parentId: $child->id)))
        ->toThrow(HierarchyDepthExceeded::class);
});

it('validates credit limits across inherited corporate pools and forbids combining PaymentTerms with recurring SellingPlans', function (): void {
    $hq = B2b::businessUnits()->create(new CreateBusinessUnit('globex-hq', 'Globex HQ', Currency::of('USD')));
    $usWest = B2b::businessUnits()->create(new CreateBusinessUnit('globex-west', 'Globex US West', Currency::of('USD'), parentId: $hq->id));

    B2b::creditAccounts()->configure(new ConfigureCreditAccount($hq->id, Money::of('20000.00', 'USD')));

    $depositTerms = B2b::paymentTerms()->save(PaymentTerms::depositSchedule('dep_25_net30', [
        new DepositMilestone(Rate::bp(2500), 0, '25% Deposit'),
        new DepositMilestone(Rate::bp(7500), 30, '75% Net 30'),
    ]), $hq->id);

    // 25% of $16,000 = $4,000 due now; 75% = $12,000 deferred (fits $20,000 credit limit!)
    $valid = B2b::creditValidator()->validate(new ValidateCreditTerms(
        businessUnitId: $usWest->id,
        paymentTerms: $depositTerms,
        orderTotal: Money::of('16000.00', 'USD'),
        sellingPlans: [SellingPlanSummary::deposit('preorder-deposit')],
    ));

    expect($valid->schedule->dueNowAmount)->toEqual(Money::of('4000.00', 'USD'))
        ->and($valid->schedule->deferredAmount)->toEqual(Money::of('12000.00', 'USD'));

    // Forbids recurring SellingPlan with Net15, Net30, Net60, or DepositSchedule!
    foreach ([PaymentTerms::net15(), PaymentTerms::net30(), PaymentTerms::net60(), $depositTerms] as $terms) {
        expect(static fn () => B2b::creditValidator()->validate(new ValidateCreditTerms(
            businessUnitId: $usWest->id,
            paymentTerms: $terms,
            orderTotal: Money::of('500.00', 'USD'),
            sellingPlans: [SellingPlanSummary::recurring('monthly-replenish')],
        )))->toThrow(RecurringSellingPlanIncompatibleWithPaymentTerms::class);
    }

    // Reserve $12,000, capture $12,000 -> $8,000 available; attempting Net30 for $9,000 fails with CreditLimitExceeded
    $hold = B2b::creditAccounts()->reserve(new ReserveCredit($usWest->id, 'order:ord-1', Money::of('12000.00', 'USD')));
    expect($hold->amount)->toEqual(Money::of('12000.00', 'USD'));

    expect(static fn () => B2b::creditValidator()->validate(new ValidateCreditTerms(
        businessUnitId: $usWest->id,
        paymentTerms: PaymentTerms::net30(),
        orderTotal: Money::of('9000.00', 'USD'),
    )))->toThrow(CreditLimitExceeded::class);

    B2b::creditAccounts()->capture(new CaptureCredit('order:ord-1'));
    $repaid = B2b::creditAccounts()->repay($usWest->id, Money::of('5000.00', 'USD'));
    expect($repaid->usedCredit)->toEqual(Money::of('7000.00', 'USD'))
        ->and($repaid->availableCredit)->toEqual(Money::of('13000.00', 'USD'));

    $hold2 = B2b::creditAccounts()->reserve(new ReserveCredit($usWest->id, 'order:ord-2', Money::of('3000.00', 'USD')));
    $released = B2b::creditAccounts()->release(new ReleaseCredit($hold2->reference));
    expect($released->availableCredit)->toEqual(Money::of('13000.00', 'USD'));

    // Frozen account blocks validation
    B2b::creditAccounts()->configure(new ConfigureCreditAccount($hq->id, Money::of('20000.00', 'USD'), CreditAccountStatus::Frozen));
    expect(static fn () => B2b::creditValidator()->validate(new ValidateCreditTerms(
        businessUnitId: $usWest->id,
        paymentTerms: PaymentTerms::net15(),
        orderTotal: Money::of('100.00', 'USD'),
    )))->toThrow(CreditAccountFrozen::class);
});
