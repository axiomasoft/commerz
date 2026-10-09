<?php

declare(strict_types=1);

use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Definitions\Data\ListPromotions;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Data\UpdatePromotion;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Commerz\Promotions\Facades\Promotions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

/** An order's redemption as a placed order leaves it, written by SQL so the counters are not touched. */
function pdfRedeem(string $promotion, string $order, ?string $customer, int $discountMinor, string $state = 'consumed', string $currency = 'RUB'): void
{
    $redemption = Uuid7::generate();

    DB::table('cmz_promotion_redemptions')->insert([
        'id' => $redemption,
        'order_ref' => $order,
        'customer_ref' => $customer,
        'fingerprint' => str_repeat('a', 64),
        'state' => $state,
        'restore_reason' => $state === 'restored' ? 'cancelled' : null,
        'consumed_at' => now(),
        'restored_at' => $state === 'restored' ? now() : null,
    ]);
    DB::table('cmz_promotion_usages')->insert([
        'redemption_id' => $redemption,
        'promotion_id' => $promotion,
        'discount_minor' => $discountMinor,
        'currency' => $currency,
        'created_at' => now(),
    ]);
}

/** @return array{used: int, spent: int, customers: array<string, int>} */
function pdfCounters(string $promotion): array
{
    $row = DB::table('cmz_promotions')->where('id', $promotion)->first();

    return [
        'used' => $row->used,
        'spent' => $row->spent_minor,
        'customers' => DB::table('cmz_promotion_customer_usages')->where('promotion_id', $promotion)->pluck('used', 'customer_ref')->map(static fn ($used): int => (int) $used)->all(),
    ];
}

function pdfUsedPromotion(array $with = []): string
{
    $promotion = pdfPromotion('autumn', $with + ['currency' => Currency::of('RUB')])->id->value;

    pdfRedeem($promotion, 'order:1', 'customer:a', 10_000);
    pdfRedeem($promotion, 'order:2', 'customer:a', 20_000);
    pdfRedeem($promotion, 'order:3', 'customer:b', 5_000);
    pdfRedeem($promotion, 'order:4', null, 1_000);
    pdfRedeem($promotion, 'order:5', 'customer:a', 99_000, 'restored');

    return $promotion;
}

it('keeps no counters while no limit is set', function (): void {
    $promotion = pdfUsedPromotion(['budget' => Money::of('1000.00', 'RUB')]);

    expect(pdfCounters($promotion))->toBe(['used' => 0, 'spent' => 0, 'customers' => []]);
});

it('counts only consumed usages when a total limit and a budget are set', function (): void {
    $promotion = pdfUsedPromotion();

    Promotions::promotions()->update(new UpdatePromotion(new PromotionId($promotion), limits: new UsageLimits(total: 100), budget: Money::of('1000.00', 'RUB')));

    expect(pdfCounters($promotion))->toBe(['used' => 4, 'spent' => 36_000, 'customers' => []]);
});

it('rebuilds the rows of the customers from consumed usages and drops stale ones', function (): void {
    $promotion = pdfUsedPromotion();
    DB::table('cmz_promotion_customer_usages')->insert(['promotion_id' => $promotion, 'customer_ref' => 'customer:ghost', 'used' => 7]);
    DB::table('cmz_promotion_customer_usages')->insert(['promotion_id' => $promotion, 'customer_ref' => 'customer:a', 'used' => 9]);

    Promotions::promotions()->update(new UpdatePromotion(new PromotionId($promotion), limits: new UsageLimits(perCustomer: 5)));

    expect(pdfCounters($promotion))->toBe(['used' => 0, 'spent' => 0, 'customers' => ['customer:a' => 2, 'customer:b' => 1]]);
});

it('resets the counters and the customer rows when the limits are lifted', function (): void {
    $promotion = pdfUsedPromotion(['limits' => new UsageLimits(total: 10, perCustomer: 3), 'budget' => Money::of('1000.00', 'RUB')]);
    $id = new PromotionId($promotion);
    DB::table('cmz_promotions')->where('id', $promotion)->update(['used' => 4, 'spent_minor' => 36_000]);
    DB::table('cmz_promotion_customer_usages')->insert(['promotion_id' => $promotion, 'customer_ref' => 'customer:a', 'used' => 2]);

    Promotions::promotions()->update(new UpdatePromotion($id, limits: new UsageLimits, clearBudget: true));

    expect(pdfCounters($promotion))->toBe(['used' => 0, 'spent' => 0, 'customers' => []]);
});

it('recomputes when only one of the limits changes and leaves the other counters exact', function (): void {
    $promotion = pdfUsedPromotion(['limits' => new UsageLimits(total: 10)]);
    $id = new PromotionId($promotion);

    Promotions::promotions()->update(new UpdatePromotion($id, budget: Money::of('500.00', 'RUB')));

    expect(pdfCounters($promotion))->toBe(['used' => 4, 'spent' => 36_000, 'customers' => []]);

    Promotions::promotions()->update(new UpdatePromotion($id, limits: new UsageLimits(total: 20, perCustomer: 2)));

    expect(pdfCounters($promotion))->toBe(['used' => 4, 'spent' => 36_000, 'customers' => ['customer:a' => 2, 'customer:b' => 1]]);
});

it('does not recompute for a change of anything but the limits and the budget', function (): void {
    $promotion = pdfUsedPromotion(['limits' => new UsageLimits(total: 10)]);
    DB::table('cmz_promotions')->where('id', $promotion)->update(['used' => 2]);

    Promotions::promotions()->update(new UpdatePromotion(new PromotionId($promotion), name: 'Fall', priority: 4));

    expect(pdfCounters($promotion)['used'])->toBe(2);
});

it('reports the orders and discounts of consumed usages, one total per currency sorted by code', function (): void {
    $promotion = pdfPromotion('anywhere');
    pdfRedeem($promotion->id->value, 'order:1', 'customer:a', 10_000);
    pdfRedeem($promotion->id->value, 'order:2', 'customer:b', 2_500);
    pdfRedeem($promotion->id->value, 'order:3', 'customer:b', 700, currency: 'USD');
    pdfRedeem($promotion->id->value, 'order:4', 'customer:b', 50_000, 'restored');

    $usage = Promotions::promotions()->find($promotion->id)->usage;

    expect($usage->orders)->toBe(3)
        ->and(array_map(static fn (Money $total): string => $total->toDecimal().' '.$total->currency->code, $usage->discounts))->toBe(['125.00 RUB', '7.00 USD'])
        ->and(Promotions::promotions()->list(new ListPromotions)->items[0]->usage->orders)->toBe(3);
});
