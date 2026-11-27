<?php

declare(strict_types=1);

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Promotions\Campaigns\Data\CreateCampaign;
use Commerz\Promotions\Campaigns\Data\UpdateCampaign;
use Commerz\Promotions\Coupons\Data\CreateCoupon;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Usage\Data\HoldCoupon;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

require_once __DIR__.'/../Usage/Helpers.php';

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        Assert::markTestSkipped('pcntl is required.');
    }

    $this->pendingArtisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_coupon_holds, cmz_promotion_usages, cmz_promotion_redemptions, cmz_promotion_customer_usages,'
        .' cmz_coupons, cmz_coupon_batches, cmz_promotion_channels, cmz_promotion_rules, cmz_promotions, cmz_campaigns,'
        .' cmz_channels, cmz_stores, cmz_outbox_messages, cmz_operation_receipts cascade');
});

it('never breaches CampaignBudget spend_cap_minor under concurrent checkouts (INV-PRM-001)', function (): void {
    $campaign = Promotions::campaigns()->create(new CreateCampaign(
        code: 'bf-budget',
        name: 'Black Friday Budget',
        currency: Currency::of('RUB'),
        spendCap: Money::of('1000.00', 'RUB'),
    ));

    $p1 = pdfPromotion('bf-a', ['campaignId' => $campaign->id])->id->value;
    $p2 = pdfPromotion('bf-b', ['campaignId' => $campaign->id])->id->value;

    $outcomes = Concurrency::run(20, fn (int $worker): string => usgOutcome(
        fn () => usgConsume("order:camp:{$worker}", [usgApplied($worker % 2 === 0 ? $p1 : $p2, '100.00')]),
    ), timeoutSeconds: 60);

    $counts = array_count_values($outcomes);
    ksort($counts);

    $budget = Promotions::campaigns()->budget($campaign->id);

    expect($counts)->toBe(['limit:campaign_budget' => 10, 'ok' => 10])
        ->and($budget->spentMinor)->toBe(100_000)
        ->and($budget->remainingSpendMinor())->toBe(0);

    $this->pendingArtisan('commerz:promotions:reconcile')->assertExitCode(0);
})->group('concurrency');

it('loses no campaign spend when spend_cap_minor is updated concurrently (INV-PRM-001)', function (): void {
    $campaign = Promotions::campaigns()->create(new CreateCampaign(
        code: 'bf-live-cap',
        name: 'Live Cap',
        currency: Currency::of('RUB'),
    ));

    $promo = pdfPromotion('bf-open', ['campaignId' => $campaign->id])->id->value;

    $outcomes = Concurrency::run(9, function (int $worker) use ($campaign, $promo): array {
        if ($worker === 8) {
            usleep(100_000);
            Promotions::campaigns()->update(new UpdateCampaign($campaign->id, spendCap: Money::of('10000.00', 'RUB'), usageCap: 100));

            return ['updated'];
        }

        $results = [];

        for ($i = 0; $i < 10; $i++) {
            $results[] = usgOutcome(fn () => usgConsume("order:live:{$worker}:{$i}", [usgApplied($promo, '50.00')]));
        }

        return $results;
    }, timeoutSeconds: 60);

    $counts = array_count_values(array_merge(...$outcomes));
    ksort($counts);
    $budget = Promotions::campaigns()->budget($campaign->id);

    expect($counts)->toBe(['ok' => 80, 'updated' => 1])
        ->and($budget->used)->toBe(80)
        ->and($budget->spentMinor)->toBe(400_000);

    $this->pendingArtisan('commerz:promotions:reconcile')->assertExitCode(0);
})->group('concurrency');

it('lets exactly one concurrent checkout hold a single-use coupon in hold_on_checkout mode', function (): void {
    config()->set('commerz-promotions.hold_on_checkout', true);
    $promo = cpnPromotion('hold-race');
    Promotions::coupons()->create(new CreateCoupon($promo->id, 'ONCEHOLD', usageLimit: 1));

    $outcomes = Concurrency::run(10, function (int $worker): string {
        config()->set('commerz-promotions.hold_on_checkout', true);

        try {
            Promotions::couponHolds()->hold(new HoldCoupon('ONCEHOLD', "checkout:w{$worker}"));

            return 'held';
        } catch (UsageLimitReached) {
            return 'exhausted';
        }
    }, timeoutSeconds: 60);

    $counts = array_count_values($outcomes);
    ksort($counts);

    expect($counts)->toBe(['exhausted' => 9, 'held' => 1])
        ->and(DB::table('cmz_coupons')->where('code', 'ONCEHOLD')->value('held'))->toBe(1);

    $this->pendingArtisan('commerz:promotions:reconcile')->assertExitCode(0);
})->group('concurrency');
