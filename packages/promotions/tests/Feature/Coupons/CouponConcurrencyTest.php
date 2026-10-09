<?php

declare(strict_types=1);

use Commerz\Identity\IdempotencyKey;
use Commerz\Promotions\Coupons\Data\GenerateCoupons;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';

// Committed data across processes: no transactional isolation; the tables are emptied after each test. Lock timeouts
// stay at the default (3 s): a deadlock surfaces as SQLSTATE 40P01 and fails the worker.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    $this->artisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_coupons, cmz_coupon_batches, cmz_promotion_channels, cmz_promotion_rules, cmz_promotions,'
        .' cmz_channels, cmz_stores, cmz_outbox_messages, cmz_operation_receipts cascade');
});

it('creates every code once when processes generate from one pattern', function (): void {
    $promotion = cpnPromotion()->id->value;

    // 31^4 codes for 2000 wanted: the processes meet each other's codes now and then and fill the gaps in later rounds.
    $counts = Concurrency::run(4, fn (int $worker): int => Promotions::coupons()
        ->generate(new GenerateCoupons(new PromotionId($promotion), 500, 'X-####', usageLimit: 1))
        ->count, timeoutSeconds: 60);

    expect($counts)->toBe([500, 500, 500, 500])
        ->and(DB::table('cmz_coupons')->count())->toBe(2000)
        ->and(DB::table('cmz_coupons')->distinct()->count('code'))->toBe(2000)
        ->and(DB::table('cmz_coupon_batches')->count())->toBe(4)
        ->and(DB::table('cmz_coupons')->select('batch_id')->groupBy('batch_id')->havingRaw('count(*) <> 500')->count())->toBe(0);
})->group('concurrency');

it('keeps one batch when processes retry a keyed generation', function (): void {
    $promotion = cpnPromotion()->id->value;

    $ids = Concurrency::run(4, fn (int $worker): string => Promotions::coupons()
        ->generate(new GenerateCoupons(new PromotionId($promotion), 300, '########', idempotencyKey: IdempotencyKey::of('import-1')))
        ->id->value, timeoutSeconds: 60);

    expect(array_unique($ids))->toHaveCount(1)
        ->and(DB::table('cmz_coupons')->count())->toBe(300)
        ->and(DB::table('cmz_coupon_batches')->count())->toBe(1);
})->group('concurrency');

it('avoids deadlock when later generation rounds collide with codes held by another batch', function (): void {
    config()->set('commerz-promotions.coupons.alphabet', 'ABCD');
    $promotion = cpnPromotion()->id->value;
    cpnCoupon(new PromotionId($promotion), 'X-CCCC');
    cpnCoupon(new PromotionId($promotion), 'X-DDDD');

    $counts = Concurrency::run(2, function (int $worker) use ($promotion): int {
        require_once __DIR__.'/../../Fixtures/CollisionRandom.php';
        $codes = $worker === 0 ? ['AAAA', 'CCCC', 'BBBB', 'CAAA'] : ['BBBB', 'DDDD', 'AAAA', 'CBBB'];
        $GLOBALS['coupon_collision_indexes'] = array_map(static fn (string $letter): int => ord($letter) - ord('A'), str_split(implode('', $codes)));
        $inserts = 0;
        DB::listen(static function ($query) use (&$inserts): void {
            if (str_starts_with($query->sql, 'insert into "cmz_coupons"') && str_contains($query->sql, 'returning id') && ++$inserts === 1) {
                // Both first rounds hold one new code; their next rounds ask for each other's code.
                usleep(250_000);
            }
        });

        return Promotions::coupons()->generate(new GenerateCoupons(new PromotionId($promotion), 2, 'X-####'))->count;
    }, timeoutSeconds: 15);

    expect($counts)->toBe([2, 2])
        ->and(DB::table('cmz_coupons')->count())->toBe(6)
        ->and(DB::table('cmz_coupon_batches')->count())->toBe(2)
        ->and(DB::table('cmz_coupons')->whereNotNull('batch_id')->select('batch_id')->groupBy('batch_id')->havingRaw('count(*) <> 2')->count())->toBe(0);
})->group('concurrency');
