<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\AppliedPromotion;
use Commerz\Ports\Promotions\Data\ConsumePromotionUsage;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Promotions\Campaigns\Data\ArchiveCampaign;
use Commerz\Promotions\Campaigns\Data\ConsumeCampaignBudget;
use Commerz\Promotions\Campaigns\Data\CreateCampaign;
use Commerz\Promotions\Campaigns\Data\ListCampaigns;
use Commerz\Promotions\Campaigns\Data\PauseCampaign;
use Commerz\Promotions\Campaigns\Data\RestoreCampaignBudget;
use Commerz\Promotions\Campaigns\Data\ResumeCampaign;
use Commerz\Promotions\Campaigns\Data\UpdateCampaign;
use Commerz\Promotions\Campaigns\Enums\CampaignStatus;
use Commerz\Promotions\Campaigns\Exceptions\CampaignBudgetExceeded;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Definitions\Data\CreatePromotion;
use Commerz\Promotions\Evaluation\Internal\NearMissEvaluator;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Usage\Contracts\CouponHolds;
use Commerz\Promotions\Usage\Data\HoldCoupon;
use Commerz\Promotions\Usage\Data\RedeemCouponHold;
use Commerz\Promotions\Usage\Data\ReleaseCouponHold;
use Commerz\Promotions\Usage\Enums\CouponHoldState;
use Commerz\Promotions\Usage\Exceptions\CouponHoldDisabled;
use Commerz\Promotions\Usage\Exceptions\CouponHoldUnavailable;
use Commerz\Promotions\Usage\Internal\DatabasePromotionUsage;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PromotionsSqliteTransactions implements Transactions
{
    public function run(callable $work, int $lockTimeoutMs = 3000): mixed
    {
        return DB::connection('promotions_sqlite')->transaction($work);
    }

    public function inTransaction(): bool
    {
        return DB::connection('promotions_sqlite')->transactionLevel() > 0;
    }
}

final class ThrowingNearMissEvaluator implements NearMissEvaluator
{
    public function evaluate(QuoteState $state): array
    {
        throw new RuntimeException('near-miss backend unavailable');
    }
}

final class SlowNearMissEvaluator implements NearMissEvaluator
{
    public function __construct(private int $sleepMs) {}

    public function evaluate(QuoteState $state): array
    {
        usleep($this->sleepMs * 1000);

        return [];
    }
}

function p2Channel(): ChannelId
{
    $storeId = Uuid7::generate();
    $channelId = Uuid7::generate();
    $now = '2026-11-26 12:00:00.000000+00';

    DB::connection('promotions_sqlite')->table('cmz_stores')->insert([
        'id' => $storeId, 'code' => 'store-'.substr($storeId, -6), 'name' => 'Store', 'default_currency' => 'RUB', 'order_number_prefix' => 'ORD', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::connection('promotions_sqlite')->table('cmz_channels')->insert([
        'id' => $channelId, 'store_id' => $storeId, 'code' => 'ch-'.substr($channelId, -6), 'name' => 'Web', 'currencies' => '["RUB"]', 'default_currency' => 'RUB', 'locales' => '["ru"]', 'default_locale' => 'ru', 'prices_include_tax' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);

    return new ChannelId($channelId);
}

/** @param list<array{id: string, price: string, qty?: int}> $lines */
function p2QuoteInput(ChannelId $channel, array $lines, array $codes = [], ?CustomerRef $customer = null): QuoteInput
{
    $context = CommerceContext::for($channel, Currency::of('RUB'), 'ru', new DateTimeImmutable('2026-11-26T12:00:00Z'));

    if ($customer !== null) {
        $context = $context->withCustomer($customer);
    }

    $inputs = array_map(
        static fn (array $line): QuoteLineInput => new QuoteLineInput(
            $line['id'],
            new SellableSnapshot(
                SellableRef::of('variant', 'item-'.$line['id']),
                'SKU-'.$line['id'],
                'Item '.$line['id'],
                SellableKind::Physical,
                true,
                basePrice: Money::of($line['price'], 'RUB'),
            ),
            $line['qty'] ?? 1,
        ),
        $lines,
    );

    return new QuoteInput($context, $inputs, $codes);
}

beforeEach(function (): void {
    Commerce::fake();

    config()->set('database.connections.promotions_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('commerz.connection', 'promotions_sqlite');
    DB::purge('promotions_sqlite');

    app()->singleton(Transactions::class, PromotionsSqliteTransactions::class);
    app()->singleton(PromotionUsage::class, DatabasePromotionUsage::class);
    $clock = new FrozenClock(new DateTimeImmutable('2026-11-26T12:00:00Z'));
    app()->instance(Clock::class, $clock);

    $schema = Schema::connection('promotions_sqlite');

    $schema->create('cmz_stores', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('code', 64)->unique();
        $table->string('name', 255);
        $table->char('default_currency', 3);
        $table->string('order_number_prefix', 16)->default('ORD');
        $table->string('status', 16)->default('active');
        $table->string('created_at', 64);
        $table->string('updated_at', 64);
    });

    $schema->create('cmz_channels', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('store_id');
        $table->string('code', 64)->unique();
        $table->string('name', 255);
        $table->text('currencies');
        $table->char('default_currency', 3);
        $table->text('locales');
        $table->string('default_locale', 16);
        $table->boolean('prices_include_tax')->default(true);
        $table->string('status', 16)->default('active');
        $table->string('created_at', 64);
        $table->string('updated_at', 64);
    });

    $schema->create('cmz_campaigns', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('code', 64)->unique();
        $table->string('name', 255);
        $table->string('status', 16)->default('active');
        $table->string('starts_at', 64)->nullable();
        $table->string('ends_at', 64)->nullable();
        $table->char('currency', 3)->nullable();
        $table->bigInteger('spend_cap_minor')->nullable();
        $table->integer('usage_cap')->nullable();
        $table->bigInteger('spent_minor')->default(0);
        $table->integer('used')->default(0);
        $table->string('created_at', 64);
        $table->string('updated_at', 64);
    });

    $schema->create('cmz_promotions', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('code', 64)->unique();
        $table->string('name', 255);
        $table->string('stage', 16);
        $table->string('activator', 64);
        $table->string('stacking', 16);
        $table->boolean('stop_further')->default(false);
        $table->integer('priority')->default(0);
        $table->bigInteger('budget_minor')->nullable();
        $table->char('currency', 3)->nullable();
        $table->string('status', 16)->default('active');
        $table->string('starts_at', 64)->nullable();
        $table->string('ends_at', 64)->nullable();
        $table->integer('usage_limit')->nullable();
        $table->integer('per_customer_limit')->nullable();
        $table->integer('used')->default(0);
        $table->bigInteger('spent_minor')->default(0);
        $table->integer('revision')->default(1);
        $table->uuid('campaign_id')->nullable();
        $table->string('created_at', 64);
        $table->string('updated_at', 64);
    });

    $schema->create('cmz_promotion_channels', static function (Blueprint $table): void {
        $table->uuid('promotion_id');
        $table->uuid('channel_id');
        $table->string('created_at', 64);
        $table->primary(['promotion_id', 'channel_id']);
    });

    $schema->create('cmz_promotion_rules', static function (Blueprint $table): void {
        $table->increments('id');
        $table->uuid('promotion_id');
        $table->string('role', 16);
        $table->integer('position');
        $table->string('rule_key', 64);
        $table->integer('config_version');
        $table->text('config');
        $table->unique(['promotion_id', 'role', 'position']);
    });

    $schema->create('cmz_promotion_customer_usages', static function (Blueprint $table): void {
        $table->uuid('promotion_id');
        $table->string('customer_ref', 191);
        $table->integer('used')->default(0);
        $table->string('updated_at', 64);
        $table->primary(['promotion_id', 'customer_ref']);
    });

    $schema->create('cmz_coupon_batches', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('promotion_id');
        $table->string('pattern', 64);
        $table->integer('count');
        $table->integer('usage_limit')->nullable();
        $table->string('created_at', 64);
    });

    $schema->create('cmz_coupons', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('promotion_id');
        $table->uuid('batch_id')->nullable();
        $table->string('code', 64)->unique();
        $table->string('status', 16)->default('active');
        $table->integer('usage_limit')->nullable();
        $table->integer('used')->default(0);
        $table->integer('held')->default(0);
        $table->string('created_at', 64);
        $table->string('updated_at', 64);
    });

    $schema->create('cmz_coupon_holds', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('coupon_id');
        $table->uuid('promotion_id');
        $table->string('hold_ref', 191);
        $table->string('customer_ref', 191)->nullable();
        $table->string('order_ref', 191)->nullable();
        $table->string('state', 16)->default('held');
        $table->string('held_at', 64);
        $table->string('expires_at', 64);
        $table->string('redeemed_at', 64)->nullable();
        $table->string('released_at', 64)->nullable();
        $table->unique(['coupon_id', 'hold_ref']);
    });

    $schema->create('cmz_promotion_redemptions', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('order_ref', 191)->unique();
        $table->string('customer_ref', 191)->nullable();
        $table->char('fingerprint', 64);
        $table->string('state', 16);
        $table->string('restore_reason', 16)->nullable();
        $table->string('consumed_at', 64);
        $table->string('restored_at', 64)->nullable();
    });

    $schema->create('cmz_promotion_usages', static function (Blueprint $table): void {
        $table->increments('id');
        $table->uuid('redemption_id');
        $table->uuid('promotion_id');
        $table->uuid('coupon_id')->nullable();
        $table->uuid('campaign_id')->nullable();
        $table->bigInteger('discount_minor');
        $table->char('currency', 3);
        $table->string('created_at', 64);
        $table->unique(['redemption_id', 'promotion_id']);
    });
});

it('enforces shared CampaignBudget spend_cap_minor and usage_cap atomically across promotions (INV-PRM-001)', function (): void {
    $channel = p2Channel();
    $campaign = Promotions::campaigns()->create(new CreateCampaign(
        code: 'bf-2026',
        name: 'Black Friday 2026',
        currency: Currency::of('RUB'),
        spendCap: Money::of('1000.00', 'RUB'),
        usageCap: 3,
    ));

    $p1 = Promotions::promotions()->create(new CreatePromotion(
        code: 'bf-ten',
        name: 'BF 10% Off',
        stage: PromotionStage::Cart,
        activator: 'automatic',
        channels: [$channel],
        effects: [new RuleSpec('order.fixed_off', ['amount' => Money::of('400.00', 'RUB')])],
        campaignId: $campaign->id,
    ));

    $p2 = Promotions::promotions()->create(new CreatePromotion(
        code: 'bf-extra',
        name: 'BF Extra 300',
        stage: PromotionStage::Cart,
        activator: 'automatic',
        channels: [$channel],
        effects: [new RuleSpec('order.fixed_off', ['amount' => Money::of('300.00', 'RUB')])],
        campaignId: $campaign->id,
    ));

    expect($p1->campaignId?->equals($campaign->id))->toBeTrue();

    $usage = app(PromotionUsage::class);

    // Order 1 uses both promotions from the same campaign (400 + 300 = 700 RUB, 2 uses)
    $usage->consume(new ConsumePromotionUsage('order:1', null, [
        new AppliedPromotion($p1->id->value, null, Money::of('400.00', 'RUB')),
        new AppliedPromotion($p2->id->value, null, Money::of('300.00', 'RUB')),
    ]));

    $after1 = Promotions::campaigns()->budget($campaign->id);
    expect($after1->spentMinor)->toBe(70_000)
        ->and($after1->used)->toBe(2)
        ->and($after1->remainingSpendMinor())->toBe(30_000)
        ->and($after1->remainingUsage())->toBe(1);

    // Order 2 asks for 400 RUB -> breaches spend_cap_minor (700 + 400 > 1000) and rolls back completely
    expect(fn () => $usage->consume(new ConsumePromotionUsage('order:2', null, [
        new AppliedPromotion($p1->id->value, null, Money::of('400.00', 'RUB')),
    ])))->toThrow(fn (UsageLimitReached $e) => expect($e->limit)->toBe('campaign_budget'));

    // Still 700 RUB spent, 2 uses (atomic rollback)
    expect(Promotions::campaigns()->budget($campaign->id)->spentMinor)->toBe(70_000)
        ->and(Promotions::campaigns()->budget($campaign->id)->used)->toBe(2);

    // Order 3 asks for 300 RUB -> fits both caps exactly (1000 RUB, 3 uses)
    $usage->consume(new ConsumePromotionUsage('order:3', null, [
        new AppliedPromotion($p2->id->value, null, Money::of('300.00', 'RUB')),
    ]));

    expect(Promotions::campaigns()->budget($campaign->id)->isExhausted())->toBeTrue();

    // Order 4 fails on usage_cap (or budget)
    expect(fn () => $usage->consume(new ConsumePromotionUsage('order:4', null, [
        new AppliedPromotion($p2->id->value, null, Money::of('10.00', 'RUB')),
    ])))->toThrow(UsageLimitReached::class);

    // Quoting sees the exhausted campaign and skips its promotions
    $exhaustedQuote = app(QuotePipeline::class)->run(p2QuoteInput($channel, [['id' => '1', 'price' => '1000.00']]));
    expect($exhaustedQuote->totals->discountTotal->isZero())->toBeTrue();

    // Restoring order 3 gives back 300 RUB and 1 usage
    $usage->restore(new RestorePromotionUsage('order:3', RestoreReason::Cancelled));
    $restored = Promotions::campaigns()->budget($campaign->id);
    expect($restored->spentMinor)->toBe(70_000)
        ->and($restored->used)->toBe(2);

    // Direct budget consume/restore and lifecycle transitions
    Promotions::campaigns()->consumeBudget(new ConsumeCampaignBudget($campaign->id, Money::of('300.00', 'RUB'), 1));
    expect(fn () => Promotions::campaigns()->consumeBudget(new ConsumeCampaignBudget($campaign->id, Money::of('1.00', 'RUB'), 1)))
        ->toThrow(CampaignBudgetExceeded::class);
    Promotions::campaigns()->restoreBudget(new RestoreCampaignBudget($campaign->id, Money::of('300.00', 'RUB'), 1));

    // Lifecycle: pause -> resume -> update -> list -> archive
    expect(Promotions::campaigns()->pause(new PauseCampaign($campaign->id))->status)->toBe(CampaignStatus::Paused)
        ->and(Promotions::campaigns()->resume(new ResumeCampaign($campaign->id))->status)->toBe(CampaignStatus::Active)
        ->and(Promotions::campaigns()->update(new UpdateCampaign($campaign->id, spendCap: Money::of('2000.00', 'RUB')))->budget->spendCapMinor)->toBe(200_000)
        ->and(Promotions::campaigns()->list(new ListCampaigns(status: CampaignStatus::Active))->items)->toHaveCount(1)
        ->and(Promotions::campaigns()->archive(new ArchiveCampaign($campaign->id))->status)->toBe(CampaignStatus::Archived);
});

it('supports optional 2-phase coupon holds (hold_on_checkout with TTL: held -> redeemed / released)', function (): void {
    $channel = p2Channel();
    $promo = Promotions::promotions()->create(new CreatePromotion(
        code: 'vip-coupon',
        name: 'VIP 100 Off',
        stage: PromotionStage::Cart,
        activator: 'coupon',
        channels: [$channel],
        effects: [new RuleSpec('order.fixed_off', ['amount' => Money::of('100.00', 'RUB')])],
    ));
    DB::connection('promotions_sqlite')->table('cmz_coupons')->insert([
        'id' => Uuid7::generate(),
        'promotion_id' => $promo->id->value,
        'code' => 'VIP1',
        'status' => 'active',
        'usage_limit' => 1,
        'used' => 0,
        'held' => 0,
        'created_at' => '2026-11-26 12:00:00.000000+00',
        'updated_at' => '2026-11-26 12:00:00.000000+00',
    ]);

    // Disabled by default (D13)
    expect(fn () => Promotions::couponHolds()->hold(new HoldCoupon('VIP1', 'checkout:1')))
        ->toThrow(CouponHoldDisabled::class);

    // Enable hold_on_checkout
    config()->set('commerz-promotions.hold_on_checkout', true);
    config()->set('commerz-promotions.hold_ttl_seconds', 300);
    app()->forgetInstance(PromotionsOptions::class);
    app()->forgetInstance(CouponHolds::class);
    app()->forgetInstance(Commerz\Promotions\Promotions::class);
    Promotions::clearResolvedInstances();

    $hold1 = Promotions::couponHolds()->hold(new HoldCoupon('  vip1 ', 'checkout:1', CustomerRef::fromString('customer:1')));
    expect($hold1->state)->toBe(CouponHoldState::Held)
        ->and($hold1->couponCode)->toBe('VIP1')
        ->and($hold1->expiresAt->format('c'))->toBe('2026-11-26T12:05:00+00:00');

    // Idempotent repeat for the same (coupon, holdRef)
    $repeat = Promotions::couponHolds()->hold(new HoldCoupon('VIP1', 'checkout:1'));
    expect($repeat->id->equals($hold1->id))->toBeTrue();

    // Another checkout cannot hold or consume the 1-use coupon while held
    expect(fn () => Promotions::couponHolds()->hold(new HoldCoupon('VIP1', 'checkout:2')))
        ->toThrow(UsageLimitReached::class);
    expect(fn () => app(PromotionUsage::class)->consume(new ConsumePromotionUsage('order:other', null, [
        new AppliedPromotion($promo->id->value, 'VIP1', Money::of('100.00', 'RUB')),
    ])))->toThrow(UsageLimitReached::class);

    // Releasing frees the slot immediately
    $released = Promotions::couponHolds()->release(new ReleaseCouponHold($hold1->id));
    expect($released->state)->toBe(CouponHoldState::Released)
        ->and($released->releasedAt)->not->toBeNull();
    // Release is idempotent
    expect(Promotions::couponHolds()->release(new ReleaseCouponHold($hold1->id))->state)->toBe(CouponHoldState::Released);

    // Checkout 2 can now hold and let it expire via FrozenClock
    $hold2 = Promotions::couponHolds()->hold(new HoldCoupon('VIP1', 'checkout:2', ttlSeconds: 60));
    /** @var FrozenClock $clock */
    $clock = app(Clock::class);
    $clock->advance('+61 seconds');

    // Expired hold cannot be redeemed
    expect(fn () => Promotions::couponHolds()->redeem(new RedeemCouponHold($hold2->id, 'order:late')))
        ->toThrow(fn (CouponHoldUnavailable $e) => expect($e->reason)->toBe(CouponHoldUnavailable::HOLD_EXPIRED));

    // Checkout 3 lazily expires hold 2 and acquires the slot
    $hold3 = Promotions::couponHolds()->hold(new HoldCoupon('VIP1', 'checkout:3', ttlSeconds: 300));
    expect(Promotions::couponHolds()->find($hold2->id)->state)->toBe(CouponHoldState::Released)
        ->and($hold3->state)->toBe(CouponHoldState::Held);

    // Redeem hold 3 for order:final (held -> redeemed)
    $redeemed = Promotions::couponHolds()->redeem(new RedeemCouponHold($hold3->id, 'order:final'));
    expect($redeemed->state)->toBe(CouponHoldState::Redeemed)
        ->and($redeemed->orderRef)->toBe('order:final')
        ->and($redeemed->redeemedAt)->not->toBeNull();

    // Idempotent redeem for same orderRef; conflict for another orderRef
    expect(Promotions::couponHolds()->redeem(new RedeemCouponHold($hold3->id, 'order:final'))->id->equals($hold3->id))->toBeTrue()
        ->and(fn () => Promotions::couponHolds()->redeem(new RedeemCouponHold($hold3->id, 'order:different')))->toThrow(IdempotencyConflict::class);

    // Subsequent PromotionUsage::consume for order:final does NOT double-count coupons.used
    app(PromotionUsage::class)->consume(new ConsumePromotionUsage('order:final', null, [
        new AppliedPromotion($promo->id->value, 'VIP1', Money::of('100.00', 'RUB')),
    ]));

    $couponRow = DB::connection('promotions_sqlite')->table('cmz_coupons')->where('code', 'VIP1')->first();
    expect((int) $couponRow->used)->toBe(1)
        ->and((int) $couponRow->held)->toBe(0);

    // expireDue sweeps remaining expired holds
    DB::connection('promotions_sqlite')->table('cmz_coupons')->insert([
        'id' => Uuid7::generate(),
        'promotion_id' => $promo->id->value,
        'code' => 'VIP2',
        'status' => 'active',
        'usage_limit' => 2,
        'used' => 0,
        'held' => 0,
        'created_at' => '2026-11-26 12:00:00.000000+00',
        'updated_at' => '2026-11-26 12:00:00.000000+00',
    ]);
    $expiring = Promotions::couponHolds()->hold(new HoldCoupon('VIP2', 'checkout:exp', ttlSeconds: 10));
    $clock->advance('+15 seconds');
    expect(Promotions::couponHolds()->expireDue())->toBe(1)
        ->and(Promotions::couponHolds()->find($expiring->id)->state)->toBe(CouponHoldState::Released);
});

it('applies AutoAddGiftLine in auto mode and emits SelectableGiftOffer[] on QuoteResult in selectable mode', function (): void {
    $channel = p2Channel();

    $autoGift = Promotions::promotions()->create(new CreatePromotion(
        code: 'free-mug',
        name: 'Free Coffee Mug',
        stage: PromotionStage::Cart,
        activator: 'automatic',
        channels: [$channel],
        conditions: [new RuleSpec('cart.subtotal_at_least', ['amount' => Money::of('1000.00', 'RUB')])],
        effects: [new RuleSpec('gift.auto_add_line', [
            'sellables' => ['variant:mug-red'],
            'quantity' => 1,
            'mode' => 'auto',
            'sku' => 'GIFT-MUG-RED',
            'title' => 'Free Red Mug',
        ])],
    ));

    $selectableGift = Promotions::promotions()->create(new CreatePromotion(
        code: 'pick-sample',
        name: 'Pick Free Sample',
        stage: PromotionStage::Cart,
        activator: 'automatic',
        channels: [$channel],
        conditions: [new RuleSpec('cart.subtotal_at_least', ['amount' => Money::of('1000.00', 'RUB')])],
        effects: [new RuleSpec('gift.auto_add_line', [
            'sellables' => ['variant:sample-a', 'variant:sample-b'],
            'quantity' => 1,
            'mode' => 'selectable',
            'maxSelections' => 1,
            'title' => 'Choose a free sample',
        ])],
    ));

    $result = app(QuotePipeline::class)->run(p2QuoteInput($channel, [
        ['id' => '1', 'price' => '1200.00'],
    ]));

    // Original line + auto-added zero-priced gift line
    expect($result->lines)->toHaveCount(2)
        ->and($result->lines[0]->isGift)->toBeFalse()
        ->and($result->lines[1]->isGift)->toBeTrue()
        ->and($result->lines[1]->giftPromotionId)->toBe($autoGift->id->value)
        ->and($result->lines[1]->sellable->sku)->toBe('GIFT-MUG-RED')
        ->and($result->lines[1]->total->isZero())->toBeTrue()
        ->and($result->total->toDecimal())->toBe('1200.00');

    // Selectable gift offer slot populated on QuoteResult
    expect($result->giftOffers)->toHaveCount(1)
        ->and($result->giftOffers[0]->promotionId)->toBe($selectableGift->id->value)
        ->and($result->giftOffers[0]->promotionCode)->toBe('pick-sample')
        ->and($result->giftOffers[0]->title)->toBe('Choose a free sample')
        ->and($result->giftOffers[0]->maxSelections)->toBe(1)
        ->and(array_map(static fn ($ref): string => (string) $ref, $result->giftOffers[0]->sellableRefs))
        ->toBe(['variant:sample-a', 'variant:sample-b']);
});

it('emits NearMissHint entries and never crashes QuotePipeline under FailurePolicy::IgnoreOnFailure', function (): void {
    $channel = p2Channel();

    $spendPromo = Promotions::promotions()->create(new CreatePromotion(
        code: 'spend-1000',
        name: '10% Off Over 1000',
        stage: PromotionStage::Cart,
        activator: 'automatic',
        channels: [$channel],
        conditions: [new RuleSpec('cart.subtotal_at_least', ['amount' => Money::of('1000.00', 'RUB')])],
        effects: [new RuleSpec('order.percent_off', ['rateBp' => 1000])],
    ));

    $qtyPromo = Promotions::promotions()->create(new CreatePromotion(
        code: 'buy-3-save',
        name: 'Buy 3 Save 150',
        stage: PromotionStage::Cart,
        activator: 'automatic',
        channels: [$channel],
        conditions: [new RuleSpec('cart.quantity_at_least', ['quantity' => 3])],
        effects: [new RuleSpec('order.fixed_off', ['amount' => Money::of('150.00', 'RUB')])],
    ));

    // Promotion with customer.in_group + subtotal should NOT produce a near-miss hint for a guest
    Promotions::promotions()->create(new CreatePromotion(
        code: 'vip-only-spend',
        name: 'VIP Spend 1000',
        stage: PromotionStage::Cart,
        activator: 'automatic',
        channels: [$channel],
        conditions: [
            new RuleSpec('customer.in_group', ['groups' => ['vip']]),
            new RuleSpec('cart.subtotal_at_least', ['amount' => Money::of('1000.00', 'RUB')]),
        ],
        effects: [new RuleSpec('order.fixed_off', ['amount' => Money::of('200.00', 'RUB')])],
    ));

    $quote = app(QuotePipeline::class)->run(p2QuoteInput($channel, [
        ['id' => '1', 'price' => '800.00', 'qty' => 2],
    ]));

    // Cart is 1600 RUB (so spend-1000 APPLIED! No hint for spend-1000), qty is 2 (misses buy-3-save by 1!)
    expect($quote->totals->discountTotal->toDecimal())->toBe('160.00')
        ->and($quote->nearMissHints)->toHaveCount(1)
        ->and($quote->nearMissHints[0]->promotionId)->toBe($qtyPromo->id->value)
        ->and($quote->nearMissHints[0]->missingQuantity)->toBe(1)
        ->and($quote->nearMissHints[0]->thresholdQuantity)->toBe(3);

    // Cart at 700 RUB, qty 1 -> misses both spend-1000 (by 300) and buy-3-save (by 2), never hints vip-only-spend
    $smallQuote = app(QuotePipeline::class)->run(p2QuoteInput($channel, [
        ['id' => '1', 'price' => '700.00', 'qty' => 1],
    ]));

    expect($smallQuote->nearMissHints)->toHaveCount(2);
    $byCode = [];
    foreach ($smallQuote->nearMissHints as $hint) {
        $byCode[$hint->promotionCode] = $hint;
    }
    expect($byCode['spend-1000']->promotionId)->toBe($spendPromo->id->value)
        ->and($byCode['spend-1000']->missingAmount?->toDecimal())->toBe('300.00')
        ->and($byCode['buy-3-save']->missingQuantity)->toBe(2)
        ->and(array_key_exists('vip-only-spend', $byCode))->toBeFalse();

    // FailurePolicy::IgnoreOnFailure when NearMissEvaluator throws -> quote still succeeds!
    app()->instance(NearMissEvaluator::class, new ThrowingNearMissEvaluator);
    $resilientQuote = app(QuotePipeline::class)->run(p2QuoteInput($channel, [
        ['id' => '1', 'price' => '1000.00', 'qty' => 1],
    ]));

    expect($resilientQuote->total->toDecimal())->toBe('900.00')
        ->and($resilientQuote->nearMissHints)->toBe([])
        ->and($resilientQuote->rejections)->toHaveCount(1)
        ->and($resilientQuote->rejections[0]->source)->toBe('promotions.near_miss_hints')
        ->and($resilientQuote->rejections[0]->reason)->toBe('stage_error');

    // FailurePolicy::IgnoreOnFailure when NearMissEvaluator exceeds timeout_ms -> quote still succeeds!
    config()->set('commerz-promotions.near_miss.timeout_ms', 10);
    app()->forgetInstance(PromotionsOptions::class);
    app()->instance(NearMissEvaluator::class, new SlowNearMissEvaluator(sleepMs: 30));

    $timedOutQuote = app(QuotePipeline::class)->run(p2QuoteInput($channel, [
        ['id' => '1', 'price' => '1000.00', 'qty' => 1],
    ]));

    expect($timedOutQuote->total->toDecimal())->toBe('900.00')
        ->and($timedOutQuote->rejections)->toHaveCount(1)
        ->and($timedOutQuote->rejections[0]->source)->toBe('promotions.near_miss_hints')
        ->and($timedOutQuote->rejections[0]->reason)->toBe('stage_timeout');
});
