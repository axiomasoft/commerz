<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/../Evaluation/Helpers.php';

uses(RefreshDatabase::class, EvEnvironment::class);

it('places and restores a promotion switched on by an external activation code without a package coupon', function (): void {
    $promotion = pdfPromotion('referral', ['activator' => 'test.rogue', 'limits' => new UsageLimits(total: 1), 'budget' => Money::of('20.00', 'RUB')]);
    $input = evInput(['100.00'], ['EXTERNAL']);
    $quote = evRun($input);

    expect($quote->appliedPromotions()[0]->couponCode)->toBeNull()
        ->and($quote->total->toDecimal())->toBe('90.00');
    usgConsume('order:external', $quote->appliedPromotions());

    expect(usgPromotionCounters($promotion))->toBe(['used' => 1, 'spent' => 1000])
        ->and(DB::table('cmz_promotion_usages')->value('coupon_id'))->toBeNull()
        ->and(evRejections(evRun($input)))->toBe(['promotions.cart|usage_limit_reached|EXTERNAL']);

    usgRestore('order:external');

    expect(usgPromotionCounters($promotion))->toBe(['used' => 0, 'spent' => 0])
        ->and(evRun($input)->total->toDecimal())->toBe('90.00');
    $this->artisan('commerz:promotions:reconcile')->assertExitCode(0);
});
