<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Money\Currency;
use Commerz\Promotions\Coupons\Data\DisableCoupon;
use Commerz\Promotions\Coupons\Data\SetCouponLimit;
use Commerz\Promotions\Coupons\Internal\CouponActivator;
use Commerz\Promotions\Definitions\Data\PausePromotion;
use Commerz\Promotions\Definitions\Data\PromotionView;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Data\Activation;
use Commerz\Promotions\Rules\Data\ActivationRequest;
use Commerz\Promotions\Rules\Data\ActivationResult;
use Commerz\Promotions\Rules\Data\CodeRejection;
use Commerz\Promotions\Rules\Data\PromotionSummary;
use Commerz\Stores\Data\ChannelId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Packman\Plugins\Contracts\Contributions\Contributions;

uses(RefreshDatabase::class);

require_once __DIR__.'/Helpers.php';

/**
 * @param  list<string>  $codes
 * @param  list<PromotionView>  $candidates
 */
function cpaActivate(array $codes, array $candidates): ActivationResult
{
    $context = CommerceContext::for(ChannelId::generate(), Currency::of('RUB'), at: new DateTimeImmutable('2026-10-09T12:00:00Z'));
    $summaries = array_map(static fn (PromotionView $promotion): PromotionSummary => new PromotionSummary(
        $promotion->id,
        $promotion->code,
        $promotion->name,
        $promotion->stage,
        $promotion->activator,
        $promotion->priority,
        $promotion->currency,
    ), $candidates);

    return app(CouponActivator::class)->activate(new ActivationRequest($context, $codes, $summaries));
}

/** @return list<string> reason@code of each rejection */
function cpaRejections(ActivationResult $result): array
{
    return array_map(static fn (CodeRejection $rejection): string => $rejection->reason.'@'.$rejection->code, $result->rejections);
}

it('is the coupon activator of the plugin', function (): void {
    $contributions = app(Contributions::class);

    expect($contributions->has(PromotionActivator::KIND, 'coupon'))->toBeTrue()
        ->and($contributions->get(PromotionActivator::KIND, 'coupon', PromotionActivator::class))->toBeInstanceOf(CouponActivator::class);
});

it('switches a promotion on for the canonical code and remembers the code as presented', function (): void {
    $promotion = cpnPromotion();
    cpnCoupon($promotion, 'HELLO');

    $result = cpaActivate([' hello '], [$promotion]);

    expect($result->rejections)->toBe([])
        ->and($result->activations)->toHaveCount(1)
        ->and($result->activations[0]->promotion->equals($promotion->id))->toBeTrue()
        ->and($result->activations[0]->couponCode)->toBe('HELLO')
        ->and($result->activations[0]->presentedCode)->toBe(' hello ');
});

it('does not answer a code that is no coupon', function (): void {
    $promotion = cpnPromotion();
    cpnCoupon($promotion, 'HELLO');

    $result = cpaActivate(['NOPE', 'not a code', '', 'HELLO-2'], [$promotion]);

    expect($result->activations)->toBe([])
        ->and($result->rejections)->toBe([])
        ->and(cpaActivate([], [$promotion])->activations)->toBe([]);
});

it('rejects a code that is presented twice, whatever its letter case', function (): void {
    $promotion = cpnPromotion();
    cpnCoupon($promotion, 'HELLO');

    $result = cpaActivate(['HELLO', 'hello', 'Hello'], [$promotion]);

    expect($result->activations)->toHaveCount(1)
        ->and($result->activations[0]->presentedCode)->toBe('HELLO')
        ->and(cpaRejections($result))->toBe(['duplicate_code@hello', 'duplicate_code@Hello'])
        ->and($result->rejections[0]->promotion?->equals($promotion->id))->toBeTrue();
});

it('rejects a disabled coupon', function (): void {
    $promotion = cpnPromotion();
    $coupon = cpnCoupon($promotion, 'HELLO');
    Promotions::coupons()->disable(new DisableCoupon($coupon->id));

    $result = cpaActivate(['HELLO'], [$promotion]);

    expect($result->activations)->toBe([])
        ->and(cpaRejections($result))->toBe(['coupon_disabled@HELLO']);
});

it('rejects the coupon of a promotion that is not among the candidates', function (): void {
    $active = cpnPromotion('active');
    $paused = cpnPromotion('paused');
    cpnCoupon($active, 'ONE');
    cpnCoupon($paused, 'TWO');
    Promotions::promotions()->pause(new PausePromotion($paused->id));

    // The engine hands over only the candidates that are active, in the window and in the channel.
    $result = cpaActivate(['ONE', 'TWO'], [$active]);

    expect($result->activations)->toHaveCount(1)
        ->and($result->activations[0]->couponCode)->toBe('ONE')
        ->and(cpaRejections($result))->toBe(['promotion_not_active@TWO']);
});

it('rejects a coupon whose usage limit is reached', function (): void {
    $promotion = cpnPromotion();
    $coupon = cpnCoupon($promotion, 'HELLO');
    cpnRedeem($coupon->id, 'order:1');
    cpnRedeem($coupon->id, 'order:2');

    expect(cpaActivate(['HELLO'], [$promotion])->activations)->toHaveCount(1); // no limit, no counter

    Promotions::coupons()->setLimit(new SetCouponLimit($coupon->id, 2));

    expect(cpaRejections(cpaActivate(['HELLO'], [$promotion])))->toBe(['coupon_exhausted@HELLO']);

    Promotions::coupons()->setLimit(new SetCouponLimit($coupon->id, 3));

    expect(cpaActivate(['HELLO'], [$promotion])->activations)->toHaveCount(1);
});

it('lets one code switch a promotion on and rejects a second code of the same promotion', function (): void {
    $promotion = cpnPromotion();
    $other = cpnPromotion('other');
    cpnCoupon($promotion, 'ONE');
    cpnCoupon($promotion, 'TWO');
    cpnCoupon($other, 'THREE');

    $result = cpaActivate(['ONE', 'TWO', 'THREE'], [$promotion, $other]);

    expect(array_map(static fn (Activation $activation): string => (string) $activation->couponCode, $result->activations))->toBe(['ONE', 'THREE'])
        ->and(cpaRejections($result))->toBe(['duplicate_promotion@TWO']);
});

it('reads the coupons with one query and writes nothing', function (): void {
    $promotion = cpnPromotion();
    cpnCoupon($promotion, 'ONE');
    cpnCoupon($promotion, 'TWO');
    cpnCoupon($promotion, 'THREE');

    $queries = pdfQueries(fn () => cpaActivate(['one', 'two', 'three', 'four'], [$promotion]));

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toStartWith('select')
        ->and($queries[0])->not->toContain('for ');
});
