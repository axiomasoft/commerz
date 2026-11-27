<?php

declare(strict_types=1);

use Commerz\Identity\IdempotencyKey;
use Commerz\Promotions\Coupons\Data\CreateCoupon;
use Commerz\Promotions\Coupons\Data\GenerateCoupons;
use Commerz\Promotions\Coupons\Data\ListCoupons;
use Commerz\Promotions\Coupons\Events\CouponsGenerated;
use Commerz\Promotions\Coupons\Exceptions\CodeSpaceExhausted;
use Commerz\Promotions\Coupons\Exceptions\NotACouponPromotion;
use Commerz\Promotions\Definitions\Data\ArchivePromotion;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Exceptions\PromotionArchived;
use Commerz\Promotions\Definitions\Exceptions\PromotionNotFound;
use Commerz\Promotions\Facades\Promotions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

it('generates exactly the count of unique codes that follow the pattern', function (): void {
    Event::fake([CouponsGenerated::class]);
    $promotion = cpnPromotion();

    $batch = Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 2500, 'summer-####-##', usageLimit: 3));

    $codes = stanStrings(DB::table('cmz_coupons')->where('batch_id', $batch->id->value)->pluck('code'));

    expect($batch->count)->toBe(2500)
        ->and($batch->pattern)->toBe('SUMMER-####-##')
        ->and($batch->usageLimit)->toBe(3)
        ->and($batch->promotion->equals($promotion->id))->toBeTrue()
        ->and($codes)->toHaveCount(2500)
        ->and(array_unique($codes))->toHaveCount(2500)
        ->and(array_filter($codes, static fn (string $code): bool => preg_match('/\ASUMMER-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{2}\z/', $code) !== 1))->toBe([])
        ->and(DB::table('cmz_coupons')->where('batch_id', $batch->id->value)->where('usage_limit', 3)->where('status', 'active')->where('used', 0)->count())->toBe(2500)
        ->and(DB::table('cmz_coupon_batches')->count())->toBe(1);

    Event::assertDispatchedTimes(CouponsGenerated::class, 1);
    Event::assertDispatched(CouponsGenerated::class, static fn (CouponsGenerated $event): bool => $event->count === 2500
        && $event->batch->equals($batch->id)
        && $event->promotion->equals($promotion->id));
});

it('lists a batch and takes the defaults of one use per code', function (): void {
    $promotion = cpnPromotion();
    $batch = Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 5, 'A-####'));
    Promotions::coupons()->create(new CreateCoupon($promotion->id, 'single'));

    $page = Promotions::coupons()->list(new ListCoupons($promotion->id, batch: $batch->id));

    expect($batch->usageLimit)->toBe(1)
        ->and($page->items)->toHaveCount(5)
        ->and(array_unique(array_map(static fn (object $coupon): ?int => $coupon->usageLimit, $page->items)))->toBe([1])
        ->and($page->items[0]->batch?->equals($batch->id))->toBeTrue();
});

it('adds the missing codes when some already exist', function (): void {
    // Eight characters of A and B hold 256 codes; 60 are taken, so a batch of two may meet them and ask again.
    config(['commerz-promotions.coupons.alphabet' => 'AB']);
    cpnReloadConfig();
    $promotion = cpnPromotion();

    foreach (range(0, 59) as $number) {
        cpnCoupon($promotion, strtr(sprintf('%08b', $number), ['0' => 'A', '1' => 'B']));
    }

    $batch = Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 2, '########'));

    expect($batch->count)->toBe(2)
        ->and(DB::table('cmz_coupons')->where('batch_id', $batch->id->value)->count())->toBe(2)
        ->and(DB::table('cmz_coupons')->count())->toBe(62);
});

it('creates nothing when the pattern is used up', function (): void {
    config(['commerz-promotions.coupons.alphabet' => 'AB']);
    cpnReloadConfig();
    $promotion = cpnPromotion();

    foreach (range(0, 255) as $number) {
        cpnCoupon($promotion, strtr(sprintf('%08b', $number), ['0' => 'A', '1' => 'B']));
    }

    expect(fn () => Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 1, '########')))->toThrow(CodeSpaceExhausted::class)
        ->and(DB::table('cmz_coupons')->count())->toBe(256)
        ->and(DB::table('cmz_coupon_batches')->count())->toBe(0);
});

it('refuses a pattern with too few codes for the count', function (): void {
    $promotion = cpnPromotion();

    // 31 characters in 4 places: 923,521 codes, which is 9,235 times 100.
    expect(pdfFailure(fn () => Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 9_236, 'X-####'))))->toBe('promotions.pattern_too_small @ pattern')
        ->and(pdfFailure(fn () => Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 1, 'X-###'))))->toBe('promotions.pattern_too_small @ pattern')
        ->and(Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 9_235, 'X-####'))->count)->toBe(9_235);
});

it('refuses a malformed pattern and a count past the configured maximum', function (): void {
    $promotion = cpnPromotion();

    expect(pdfFailure(fn () => Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 1, '-####'))))->toBe('promotions.invalid_pattern @ pattern')
        ->and(pdfFailure(fn () => Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 1, 'A B ####'))))->toBe('promotions.invalid_pattern @ pattern')
        ->and(pdfFailure(fn () => Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 1, str_repeat('#', 65)))))->toBe('promotions.invalid_pattern @ pattern')
        ->and(pdfFailure(fn () => Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 50_001, '########'))))->toBe('promotions.invalid_count @ count')
        ->and(pdfFailure(fn () => new GenerateCoupons($promotion->id, 0, '########')))->toBe('promotions.invalid_count @ count')
        ->and(DB::table('cmz_coupon_batches')->count())->toBe(0);
});

it('generates only for an unarchived promotion of the coupon activator', function (): void {
    $automatic = pdfPromotion('automatic');
    $archived = cpnPromotion('old');
    Promotions::promotions()->archive(new ArchivePromotion($archived->id));

    expect(fn () => Promotions::coupons()->generate(new GenerateCoupons($automatic->id, 1, '########')))->toThrow(NotACouponPromotion::class)
        ->and(fn () => Promotions::coupons()->generate(new GenerateCoupons($archived->id, 1, '########')))->toThrow(PromotionArchived::class)
        ->and(fn () => Promotions::coupons()->generate(new GenerateCoupons(PromotionId::generate(), 1, '########')))->toThrow(PromotionNotFound::class)
        ->and(DB::table('cmz_coupon_batches')->count())->toBe(0);
});

it('takes the promotion for key share, which an order being placed does not wait for', function (): void {
    $promotion = cpnPromotion();

    $queries = pdfQueries(fn () => Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 10, '########')));
    $locks = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"cmz_promotions"') && str_contains($sql, 'for ')));

    expect($locks)->toHaveCount(1)
        ->and($locks[0])->toContain('for key share')
        ->and(implode("\n", $queries))->not->toContain('for update');
});

it('returns the same batch for a retry with the same key and arguments', function (): void {
    $promotion = cpnPromotion();
    $key = IdempotencyKey::of('import-42');

    $first = Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 20, '#######', idempotencyKey: $key));
    $retry = Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 20, '#######', idempotencyKey: $key));

    expect($retry->id->equals($first->id))->toBeTrue()
        ->and($retry->count)->toBe(20)
        ->and($retry->pattern)->toBe($first->pattern)
        ->and($retry->usageLimit)->toBe($first->usageLimit)
        ->and($retry->createdAt)->toEqual($first->createdAt)
        ->and(DB::table('cmz_coupons')->count())->toBe(20)
        ->and(DB::table('cmz_coupon_batches')->count())->toBe(1);
});

it('refuses a key that is reused with other arguments, and runs again after a failure', function (): void {
    $promotion = cpnPromotion();
    $key = IdempotencyKey::of('import-43');

    Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 20, '#######', idempotencyKey: $key));

    expect(pdfFailure(fn () => Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 21, '#######', idempotencyKey: $key))))->toBe('common.idempotency_conflict')
        ->and(pdfFailure(fn () => Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 20, '#######', usageLimit: 2, idempotencyKey: $key))))->toBe('common.idempotency_conflict')
        ->and(pdfFailure(fn () => Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 20, '########', idempotencyKey: $key))))->toBe('common.idempotency_conflict')
        ->and(DB::table('cmz_coupon_batches')->count())->toBe(1);

    $failing = IdempotencyKey::of('import-44');
    $archived = cpnPromotion('old');
    Promotions::promotions()->archive(new ArchivePromotion($archived->id));

    expect(fn () => Promotions::coupons()->generate(new GenerateCoupons($archived->id, 5, '#######', idempotencyKey: $failing)))->toThrow(PromotionArchived::class)
        ->and(Promotions::coupons()->generate(new GenerateCoupons($promotion->id, 5, '#######', idempotencyKey: $failing))->count)->toBe(5);
});
