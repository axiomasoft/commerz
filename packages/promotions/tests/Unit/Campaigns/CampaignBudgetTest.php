<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Campaigns\Data\Campaign;
use Commerz\Promotions\Campaigns\Data\CampaignBudget;
use Commerz\Promotions\Campaigns\Data\CampaignId;
use Commerz\Promotions\Campaigns\Data\CreateCampaign;
use Commerz\Promotions\Campaigns\Data\UpdateCampaign;
use Commerz\Promotions\Campaigns\Enums\CampaignStatus;
use Commerz\Promotions\Usage\Data\CouponHoldId;
use Commerz\Promotions\Usage\Data\HoldCoupon;
use Commerz\Promotions\Usage\Data\RedeemCouponHold;

it('tracks campaign spend and usage caps accurately without floats', function (): void {
    $rub = Currency::of('RUB');
    $budget = new CampaignBudget(
        spendCapMinor: 100_000,
        spentMinor: 75_000,
        usageCap: 10,
        used: 8,
        currency: $rub,
    );

    expect($budget->hasCaps())->toBeTrue()
        ->and($budget->isExhausted())->toBeFalse()
        ->and($budget->spendCap()?->toDecimal())->toBe('1000.00')
        ->and($budget->spent()?->toDecimal())->toBe('750.00')
        ->and($budget->remainingSpendMinor())->toBe(25_000)
        ->and($budget->remainingSpend()?->toDecimal())->toBe('250.00')
        ->and($budget->remainingUsage())->toBe(2)
        ->and($budget->canAfford(25_000, 2))->toBeTrue()
        ->and($budget->canAfford(25_001, 1))->toBeFalse()
        ->and($budget->canAfford(100, 3))->toBeFalse();
});

it('validates campaign budget and command invariants', function (): void {
    $rub = Currency::of('RUB');
    $usd = Currency::of('USD');

    expect(fn () => new CampaignBudget(spendCapMinor: 0, currency: $rub))->toThrow(InvalidCommand::class)
        ->and(fn () => new CampaignBudget(spendCapMinor: 100))->toThrow(InvalidCommand::class)
        ->and(fn () => new CampaignBudget(spentMinor: -1))->toThrow(InvalidCommand::class)
        ->and(fn () => new CampaignBudget(usageCap: 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new CampaignBudget(used: -1))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateCampaign('c1', 'Campaign', currency: $rub, spendCap: Money::of('10.00', 'USD')))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateCampaign('c1', 'Campaign', status: CampaignStatus::Archived))->toThrow(InvalidCommand::class)
        ->and(fn () => new UpdateCampaign(CampaignId::generate(), spendCap: Money::of('10.00', $usd), clearSpendCap: true))->toThrow(InvalidCommand::class)
        ->and(fn () => new UpdateCampaign(CampaignId::generate(), usageCap: 5, clearUsageCap: true))->toThrow(InvalidCommand::class);
});

it('evaluates campaign active windows', function (): void {
    $now = new DateTimeImmutable('2026-11-26T12:00:00Z');
    $campaign = new Campaign(
        id: CampaignId::generate(),
        code: 'black-friday',
        name: 'Black Friday',
        status: CampaignStatus::Active,
        currency: Currency::of('RUB'),
        budget: new CampaignBudget(spendCapMinor: 50_000, currency: Currency::of('RUB')),
        startsAt: new DateTimeImmutable('2026-11-26T00:00:00Z'),
        endsAt: new DateTimeImmutable('2026-11-27T00:00:00Z'),
        createdAt: $now,
        updatedAt: $now,
    );

    expect($campaign->isActiveAt($now))->toBeTrue()
        ->and($campaign->isActiveAt(new DateTimeImmutable('2026-11-25T23:59:59Z')))->toBeFalse()
        ->and($campaign->isActiveAt(new DateTimeImmutable('2026-11-27T00:00:00Z')))->toBeFalse();
});

it('normalizes HoldCoupon and validates RedeemCouponHold', function (): void {
    $hold = new HoldCoupon('  save-10  ', '  checkout:1  ', ttlSeconds: 300);

    expect($hold->couponCode)->toBe('SAVE-10')
        ->and($hold->holdRef)->toBe('checkout:1')
        ->and($hold->ttlSeconds)->toBe(300)
        ->and(fn () => new HoldCoupon('SAVE10', '', ttlSeconds: 300))->toThrow(InvalidCommand::class)
        ->and(fn () => new HoldCoupon('SAVE10', 'checkout:1', ttlSeconds: 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new RedeemCouponHold(CouponHoldId::generate(), '   '))->toThrow(InvalidCommand::class);
});
