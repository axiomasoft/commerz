<?php

declare(strict_types=1);

use Commerz\Pagination\Page;
use Commerz\Promotions\Common\Exceptions\DuplicateCode;
use Commerz\Promotions\Coupons\Data\CouponBatchId;
use Commerz\Promotions\Coupons\Data\CouponId;
use Commerz\Promotions\Coupons\Data\CouponView;
use Commerz\Promotions\Coupons\Data\CreateCoupon;
use Commerz\Promotions\Coupons\Data\DisableCoupon;
use Commerz\Promotions\Coupons\Data\EnableCoupon;
use Commerz\Promotions\Coupons\Data\ListCoupons;
use Commerz\Promotions\Coupons\Data\SetCouponLimit;
use Commerz\Promotions\Coupons\Enums\CouponStatus;
use Commerz\Promotions\Coupons\Exceptions\CouponNotFound;
use Commerz\Promotions\Coupons\Exceptions\NotACouponPromotion;
use Commerz\Promotions\Definitions\Data\ArchivePromotion;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Exceptions\PromotionArchived;
use Commerz\Promotions\Definitions\Exceptions\PromotionNotFound;
use Commerz\Promotions\Facades\Promotions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

it('creates a coupon with a canonical code', function (): void {
    $promotion = cpnPromotion();

    $coupon = Promotions::coupons()->create(new CreateCoupon($promotion->id, ' autumn ', 5));

    expect($coupon->code)->toBe('AUTUMN')
        ->and($coupon->promotion->equals($promotion->id))->toBeTrue()
        ->and($coupon->batch)->toBeNull()
        ->and($coupon->status)->toBe(CouponStatus::Active)
        ->and($coupon->usageLimit)->toBe(5)
        ->and($coupon->used)->toBe(0)
        ->and(DB::table('cmz_coupons')->where('id', $coupon->id->value)->value('code'))->toBe('AUTUMN');
});

it('refuses a code that cannot be canonical and a limit below one', function (): void {
    $promotion = cpnPromotion();

    expect(pdfFailure(fn () => new CreateCoupon($promotion->id, 'two words')))->toBe('promotions.invalid_coupon_code @ code')
        ->and(pdfFailure(fn () => new CreateCoupon($promotion->id, '-first')))->toBe('promotions.invalid_coupon_code @ code')
        ->and(pdfFailure(fn () => new CreateCoupon($promotion->id, str_repeat('A', 65))))->toBe('promotions.invalid_coupon_code @ code')
        ->and(pdfFailure(fn () => new CreateCoupon($promotion->id, 'HELLO', 0)))->toBe('promotions.invalid_limits @ usageLimit');
});

it('refuses a code that is taken in any letter case and keeps the transaction usable', function (): void {
    $promotion = cpnPromotion();
    cpnCoupon($promotion, 'HELLO');

    DB::transaction(function () use ($promotion): void {
        expect(pdfFailure(fn () => cpnCoupon($promotion, 'hello')))->toBe('promotions.duplicate_code');
        // The failed insert ran in a savepoint: the surrounding transaction still works.
        expect(cpnCoupon($promotion, 'OTHER')->code)->toBe('OTHER');
    });

    expect(fn () => cpnCoupon(cpnPromotion('second'), ' Hello '))->toThrow(DuplicateCode::class)
        ->and(DB::table('cmz_coupons')->count())->toBe(2);
});

it('adds coupons only to an unarchived promotion of the coupon activator', function (): void {
    $automatic = pdfPromotion('automatic');
    $archived = cpnPromotion('old');
    Promotions::promotions()->archive(new ArchivePromotion($archived->id));

    expect(fn () => cpnCoupon($automatic))->toThrow(NotACouponPromotion::class)
        ->and(fn () => cpnCoupon($archived))->toThrow(PromotionArchived::class)
        ->and(fn () => cpnCoupon(PromotionId::generate()))->toThrow(PromotionNotFound::class)
        ->and(DB::table('cmz_coupons')->count())->toBe(0);
});

it('locks the promotion for key share on create and the coupon for update on a change', function (): void {
    $promotion = cpnPromotion();

    $create = pdfQueries(fn () => cpnCoupon($promotion));
    $coupon = Promotions::coupons()->findByCode('HELLO');
    $change = pdfQueries(fn () => Promotions::coupons()->disable(new DisableCoupon($coupon->id)));

    expect(array_filter($create, static fn (string $sql): bool => str_contains($sql, 'for key share')))->toHaveCount(1)
        ->and(implode("\n", $create))->not->toContain('for update')
        ->and(array_filter($change, static fn (string $sql): bool => str_contains($sql, '"cmz_coupons"') && str_contains($sql, 'for update')))->toHaveCount(1)
        ->and(implode("\n", $change))->not->toContain('"cmz_promotions"');
});

it('disables and enables, and repeating either changes nothing', function (): void {
    $coupon = cpnCoupon(cpnPromotion());

    $disabled = Promotions::coupons()->disable(new DisableCoupon($coupon->id));
    $again = Promotions::coupons()->disable(new DisableCoupon($coupon->id));

    expect($disabled->status)->toBe(CouponStatus::Disabled)
        ->and($again->status)->toBe(CouponStatus::Disabled)
        ->and($again->updatedAt)->toEqual($disabled->updatedAt)
        ->and($disabled->updatedAt >= $coupon->updatedAt)->toBeTrue();

    $enabled = Promotions::coupons()->enable(new EnableCoupon($coupon->id));
    $enabledAgain = Promotions::coupons()->enable(new EnableCoupon($coupon->id));

    expect($enabled->status)->toBe(CouponStatus::Active)
        ->and($enabledAgain->updatedAt)->toEqual($enabled->updatedAt)
        ->and(pdfFailure(fn () => Promotions::coupons()->disable(new DisableCoupon(CouponId::generate()))))->toBe('promotions.coupon_not_found');
});

it('counts the orders that hold the coupon when a limit is set', function (): void {
    $coupon = cpnCoupon(cpnPromotion());
    cpnRedeem($coupon->id, 'order:1');
    cpnRedeem($coupon->id, 'order:2');
    cpnRedeem($coupon->id, 'order:3', 'restored');

    // No limit, no counter: the column stays at zero while the view reads the log.
    expect(DB::table('cmz_coupons')->where('id', $coupon->id->value)->value('used'))->toBe(0)
        ->and(Promotions::coupons()->find($coupon->id)->used)->toBe(2);

    $limited = Promotions::coupons()->setLimit(new SetCouponLimit($coupon->id, 5));

    expect($limited->usageLimit)->toBe(5)
        ->and($limited->used)->toBe(2)
        ->and(DB::table('cmz_coupons')->where('id', $coupon->id->value)->value('used'))->toBe(2);

    $lifted = Promotions::coupons()->setLimit(new SetCouponLimit($coupon->id, null));

    expect($lifted->usageLimit)->toBeNull()
        ->and($lifted->used)->toBe(2)
        ->and(DB::table('cmz_coupons')->where('id', $coupon->id->value)->value('used'))->toBe(0)
        ->and(pdfFailure(fn () => new SetCouponLimit($coupon->id, -1)))->toBe('promotions.invalid_limits @ usageLimit');
});

it('finds a coupon by id and by a code in any letter case', function (): void {
    $coupon = cpnCoupon(cpnPromotion(), 'SUMMER-1');

    expect(Promotions::coupons()->find($coupon->id)->code)->toBe('SUMMER-1')
        ->and(Promotions::coupons()->findByCode('summer-1')->id->equals($coupon->id))->toBeTrue()
        ->and(Promotions::coupons()->findByCode("  Summer-1\n")->id->equals($coupon->id))->toBeTrue()
        ->and(fn () => Promotions::coupons()->findByCode('SUMMER-2'))->toThrow(CouponNotFound::class)
        ->and(fn () => Promotions::coupons()->findByCode('not a code'))->toThrow(CouponNotFound::class)
        ->and(fn () => Promotions::coupons()->find(CouponId::generate()))->toThrow(CouponNotFound::class);
});

it('lists the coupons of a promotion by code with a cursor, a status and a batch filter', function (): void {
    $promotion = cpnPromotion();
    $other = cpnPromotion('other');

    foreach (['C', 'A', 'E', 'B', 'D'] as $code) {
        cpnCoupon($promotion, $code);
    }

    cpnCoupon($other, 'Z');
    Promotions::coupons()->disable(new DisableCoupon(Promotions::coupons()->findByCode('B')->id));

    $first = Promotions::coupons()->list(new ListCoupons($promotion->id, first: 2));
    $second = Promotions::coupons()->list(new ListCoupons($promotion->id, first: 2, after: $first->next));
    $third = Promotions::coupons()->list(new ListCoupons($promotion->id, first: 2, after: $second->next));

    $codes = (static fn (Page $page): array => array_map(static function (object $coupon): string {
        if (! $coupon instanceof CouponView) {
            throw new RuntimeException('Expected a coupon.');
        }

        return $coupon->code;
    }, $page->items));

    expect($codes($first))->toBe(['A', 'B'])
        ->and($first->hasMore())->toBeTrue()
        ->and($codes($second))->toBe(['C', 'D'])
        ->and($codes($third))->toBe(['E'])
        ->and($third->hasMore())->toBeFalse()
        ->and($codes(Promotions::coupons()->list(new ListCoupons($promotion->id, status: CouponStatus::Disabled))))->toBe(['B'])
        ->and($codes(Promotions::coupons()->list(new ListCoupons($promotion->id, batch: CouponBatchId::generate()))))->toBe([])
        ->and(pdfFailure(fn () => new ListCoupons($promotion->id, first: 501)))->toBe('common.invalid_page_size @ first');
});
