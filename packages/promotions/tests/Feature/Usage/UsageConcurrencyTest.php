<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Data\UpdatePromotion;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require_once __DIR__.'/Helpers.php';

// C1–C7 of knowledge/promotions-design#usage (PRM-01). Committed data across processes: no transactional isolation; the
// tables are emptied after each test. Lock timeouts stay at the default (3 s): a deadlock surfaces as SQLSTATE 40P01
// and fails the worker, since a worker reports only `ok` or a UsageLimitReached.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        Assert::markTestSkipped('pcntl is required.');
    }

    $this->pendingArtisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_promotion_usages, cmz_promotion_redemptions, cmz_promotion_customer_usages, cmz_coupons,'
        .' cmz_coupon_batches, cmz_promotion_channels, cmz_promotion_rules, cmz_promotions, cmz_channels, cmz_stores,'
        .' cmz_outbox_messages, cmz_operation_receipts cascade');
});

/**
 * @param  list<string>  $outcomes
 * @return array<string, int>
 */
function usgTally(array $outcomes): array
{
    $counts = array_count_values($outcomes);
    ksort($counts);

    return $counts;
}

it('lets exactly the total limit of orders through (C1)', function (): void {
    $promotion = pdfPromotion('limited', ['limits' => new UsageLimits(total: 10)])->id->value;

    $outcomes = Concurrency::run(40, fn (int $worker): string => usgOutcome(
        fn () => usgConsume("order:w{$worker}", [usgApplied($promotion)]),
    ), timeoutSeconds: 60);

    expect(usgTally($outcomes))->toBe(['limit:total' => 30, 'ok' => 10])
        ->and(usgPromotionCounters($promotion)['used'])->toBe(10)
        ->and(usgConsumed($promotion))->toBe(10);
    $this->pendingArtisan('commerz:promotions:reconcile')->assertExitCode(0);
})->group('concurrency');

it('lets exactly the coupon limit of orders through (C2)', function (): void {
    $promotion = cpnPromotion('welcome')->id->value;
    cpnCoupon(new PromotionId($promotion), 'SHARED', 3);

    $outcomes = Concurrency::run(12, fn (int $worker): string => usgOutcome(
        fn () => usgConsume("order:w{$worker}", [usgApplied($promotion, coupon: 'SHARED')]),
    ), timeoutSeconds: 60);

    expect(usgTally($outcomes))->toBe(['limit:coupon' => 9, 'ok' => 3])
        ->and(usgCouponUsed('SHARED'))->toBe(3)
        ->and(usgConsumed($promotion))->toBe(3);
    $this->pendingArtisan('commerz:promotions:reconcile')->assertExitCode(0);
})->group('concurrency');

it('lets one customer through once with a per-customer limit of 1 (C3)', function (): void {
    $promotion = pdfPromotion('once', ['limits' => new UsageLimits(perCustomer: 1)])->id->value;

    $outcomes = Concurrency::run(8, fn (int $worker): string => usgOutcome(
        fn () => usgConsume("order:w{$worker}", [usgApplied($promotion)], 'customer:a'),
    ), timeoutSeconds: 60);

    expect(usgTally($outcomes))->toBe(['limit:per_customer' => 7, 'ok' => 1])
        ->and(usgCustomers($promotion))->toBe(['customer:a' => 1])
        ->and(usgConsumed($promotion))->toBe(1);
    $this->pendingArtisan('commerz:promotions:reconcile')->assertExitCode(0);
})->group('concurrency');

it('never spends more than the budget (C4)', function (): void {
    $promotion = pdfPromotion('budget', ['budget' => Money::of('1000.00', 'RUB')])->id->value;

    $outcomes = Concurrency::run(20, fn (int $worker): string => usgOutcome(
        fn () => usgConsume("order:w{$worker}", [usgApplied($promotion, '100.00')]),
    ), timeoutSeconds: 60);

    expect(usgTally($outcomes))->toBe(['limit:budget' => 10, 'ok' => 10])
        ->and(usgPromotionCounters($promotion))->toBe(['used' => 0, 'spent' => 100_000])
        ->and(usgConsumed($promotion))->toBe(10);
    $this->pendingArtisan('commerz:promotions:reconcile')->assertExitCode(0);
})->group('concurrency');

it('does not deadlock orders that list two limited promotions in opposite order (C5)', function (): void {
    $first = pdfPromotion('first', ['limits' => new UsageLimits(total: 30)])->id->value;
    $second = pdfPromotion('second', ['limits' => new UsageLimits(total: 1000)])->id->value;

    $outcomes = Concurrency::run(8, function (int $worker) use ($first, $second): array {
        $outcomes = [];

        for ($i = 0; $i < 6; $i++) {
            $applied = $worker % 2 === 0
                ? [usgApplied($first), usgApplied($second)]
                : [usgApplied($second), usgApplied($first)];
            $outcomes[] = usgOutcome(fn () => usgConsume("order:w{$worker}:{$i}", $applied));
        }

        return $outcomes;
    }, timeoutSeconds: 60);

    expect(usgTally(array_merge(...$outcomes)))->toBe(['limit:total' => 18, 'ok' => 30])
        ->and(usgPromotionCounters($first)['used'])->toBe(30)
        ->and(usgPromotionCounters($second)['used'])->toBe(30)
        ->and(usgConsumed($second))->toBe(30);
    $this->pendingArtisan('commerz:promotions:reconcile')->assertExitCode(0);
})->group('concurrency');

it('counts one order once when processes consume it together (C6)', function (): void {
    $promotion = pdfPromotion('limited', ['limits' => new UsageLimits(total: 10, perCustomer: 5)])->id->value;

    $outcomes = Concurrency::run(6, fn (int $worker): string => usgOutcome(
        fn () => usgConsume('order:1', [usgApplied($promotion)], 'customer:a'),
    ), timeoutSeconds: 60);

    expect($outcomes)->toBe(array_fill(0, 6, 'ok'))
        ->and(DB::table('cmz_promotion_redemptions')->count())->toBe(1)
        ->and(usgPromotionCounters($promotion)['used'])->toBe(1)
        ->and(usgCustomers($promotion))->toBe(['customer:a' => 1]);
    $this->pendingArtisan('commerz:promotions:reconcile')->assertExitCode(0);
})->group('concurrency');

it('loses no usage when a limit is set while orders are placed (C7)', function (): void {
    $promotion = pdfPromotion('open')->id->value;

    $outcomes = Concurrency::run(9, function (int $worker) use ($promotion): array {
        if ($worker === 8) {
            usleep(150_000); // in the middle of the orders
            Promotions::promotions()->update(new UpdatePromotion(new PromotionId($promotion), limits: new UsageLimits(total: 100)));

            return ['updated'];
        }

        $outcomes = [];

        for ($i = 0; $i < 10; $i++) {
            $outcomes[] = usgOutcome(fn () => usgConsume("order:w{$worker}:{$i}", [usgApplied($promotion)]));
        }

        return $outcomes;
    }, timeoutSeconds: 60);

    expect(usgTally(array_merge(...$outcomes)))->toBe(['ok' => 80, 'updated' => 1])
        ->and(DB::table('cmz_promotions')->where('id', $promotion)->value('usage_limit'))->toBe(100)
        ->and(usgPromotionCounters($promotion)['used'])->toBe(80)
        ->and(usgConsumed($promotion))->toBe(80);
    $this->pendingArtisan('commerz:promotions:reconcile')->assertExitCode(0);
})->group('concurrency');
