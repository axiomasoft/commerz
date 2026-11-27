<?php

declare(strict_types=1);

use Commerz\Catalog\Facades\Catalog;
use Commerz\Catalog\Listings\Data\PublishProduct;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\Variants\Data\CreateVariant;
use Commerz\Context\CommerceContext;
use Commerz\EndToEnd\Concurrency\Support\Checkout;
use Commerz\EndToEnd\Fixtures\StressGatewayPlugin;
use Commerz\EndToEnd\Fixtures\StressPaymentGateway;
use Commerz\EndToEnd\Support\CommittedDatabase;
use Commerz\EndToEnd\Support\EndToEndTestCase;
use Commerz\EndToEnd\Support\ShopWorld;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\SellableRef;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\LinkSellable;
use Commerz\Inventory\Stock\Data\AdjustStock;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Measures\LocalizedText;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Ports\StoredValue\Data\CaptureStoredValue;
use Commerz\Ports\StoredValue\Data\HoldStoredValue;
use Commerz\Ports\StoredValue\Exceptions\InsufficientStoredValue;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Promotions\Campaigns\Data\CreateCampaign;
use Commerz\Promotions\Coupons\Data\CreateCoupon;
use Commerz\Promotions\Definitions\Data\CreatePromotion;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Checkout\Exceptions\QuoteChanged;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Commerz\Sales\Errors\SalesProblemDefinitions;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\OrderChanges\Data\AddLineAction;
use Commerz\Sales\OrderChanges\Data\AddOrderChangeAction;
use Commerz\Sales\OrderChanges\Data\CreateOrderChange;
use Commerz\Sales\OrderChanges\Enums\OrderChangeStatus;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeVersionConflict;
use Commerz\Sales\Orders\Data\ListOrders;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Data\ReconcilePayment;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Commerz\Sales\Payments\Exceptions\PaymentExceedsBalance;
use Commerz\Sales\Payments\Exceptions\PaymentOutcomeUnknown;
use Commerz\StoredValue\Facades\StoredValue;
use Commerz\StoredValue\GiftCards\Data\IssueGiftCard;
use Commerz\StoredValue\Ledger\Enums\LedgerEntryType;
use Commerz\StoredValue\StoredValueServiceProvider;
use Commerz\StoredValue\Wallets\Data\CreditWallet;
use Commerz\Testkit\Concurrency;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Packman\Errors\Enums\ErrorCategory;
use PHPUnit\Framework\Assert;

// Multi-process PostgreSQL (>= 16) concurrency stress test suite covering all five high-contention invariants:
// (1) Last remaining stock oversell prevention (regular SKU + InventoryKit sharing scarce components)
// (2) Last coupon & CampaignBudget::spend_cap_minor enforcement (INV-PRM-001)
// (3) GiftCard & CustomerWallet double-spend prevention (INV-SV-001)
// (4) Concurrent OrderChange::confirm() OCC (409 ORDERS_ORDER_CHANGE_VERSION_CONFLICT, INV-OMS-001)
// (5) Payment gateway network timeout followed by concurrent reconcile & retries without double charge

trait PgStressEnvironment
{
    /** @return list<class-string> */
    protected function commerzProviders(): array
    {
        return [...EndToEndTestCase::allProviders(), StoredValueServiceProvider::class];
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.plugins', [StressGatewayPlugin::class]);
    }
}

uses(PgStressEnvironment::class);

abstract class PgConcurrencyStressCase extends EndToEndTestCase
{
    use PgStressEnvironment;
}

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        Assert::markTestSkipped('pcntl is required for multi-process PostgreSQL stress tests.');
    }

    CommittedDatabase::prepare();
    StressPaymentGateway::reset();
});

afterEach(function (): void {
    StressPaymentGateway::reset();
    CommittedDatabase::truncate();
});

/**
 * Builds a quoted cart with optional coupon code in the parent process prior to forking.
 *
 * @param  list<array{SellableRef, int}>  $lines
 * @return array{CartId, Money}
 */
function stressQuotedCart(ShopWorld $world, array $lines, string $customer, ?string $couponCode = null): array
{
    $cart = Sales::carts()->create(new CreateCart(CommerceContext::for($world->channel, Currency::of('RUB'), 'ru')))->id;
    Sales::carts()->setCustomer(new SetCartCustomer($cart, CustomerRef::of('crm', $customer)));

    foreach ($lines as [$sellable, $quantity]) {
        Sales::carts()->addLine(new AddCartLine($cart, $sellable, $quantity));
    }

    Sales::carts()->setAddress(new SetCartAddress(
        $cart,
        AddressRole::Shipping,
        new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 1'], 'Stress Buyer', '+70000000000'),
    ));
    Sales::carts()->selectDelivery(new SelectDelivery($cart, ShopWorld::COURIER));

    if ($couponCode !== null) {
        Sales::carts()->enterCode(new EnterCode($cart, $couponCode));
    }

    return [$cart, Sales::carts()->quote($cart)->total];
}

function stressAssertReconcilersClean(): void
{
    foreach ([
        'commerz:inventory:reconcile' => 'Stock is consistent.',
        'commerz:promotions:reconcile' => 'Promotions are consistent.',
    ] as $command => $expectedText) {
        $exit = Artisan::call($command);
        $output = Artisan::output();

        expect($exit)->toBe(0, $output)
            ->and($output)->toContain($expectedText);
    }
}

it('1a: never oversells the last remaining unit of a regular SKU across 16 concurrent processes', function (): void {
    $world = ShopWorld::build();
    // ShopWorld starts smallItem with 5 on hand; adjust down to exactly 1 remaining unit.
    Inventory::stock()->adjust(new AdjustStock($world->smallItem, $world->location, -4, 'leave_last_unit'));

    $carts = array_map(
        static fn (int $i): array => Checkout::cart($world, [[$world->small, 1]], "sku-stress-{$i}"),
        range(0, 15),
    );
    $deadlocksBefore = Concurrency::pgDeadlocks();

    $outcomes = Concurrency::race(
        workers: 16,
        work: static function (int $worker) use ($carts): string {
            Checkout::place($carts[$worker]);

            return 'ok';
        },
        expected: [
            InsufficientStock::class => 'insufficient_stock',
        ],
    );

    Concurrency::assertRaceSettled($outcomes, ['insufficient_stock' => 15, 'ok' => 1], $deadlocksBefore);

    $level = Inventory::stock()->level($world->smallItem, $world->location);

    expect([$level->onHand, $level->reserved, $level->available])->toBe([1, 1, 0])
        ->and(Sales::orders()->list(new ListOrders)->items)->toHaveCount(1);

    stressAssertReconcilersClean();
})->group('concurrency');

it('1b: never oversells the last remaining InventoryKit sharing scarce components across 12 concurrent processes', function (): void {
    $world = ShopWorld::build();
    $ru = static fn (string $text): LocalizedText => LocalizedText::of('ru', $text);
    $rub = Currency::of('RUB');

    // Create two InventoryKit sellables sharing the scarce smallItem component (2 small units per kit):
    // - KIT-STARTER: 2x smallItem + 1x mugItem
    // - KIT-DUO:     2x smallItem + 1x mediumItem
    $kitType = Catalog::productTypes()->create(new CreateProductType('bundle', $ru('Набор')));
    $starterProduct = Catalog::products()->create(new CreateProduct($kitType->id, 'starter-kit', $ru('Стартовый набор')));
    $duoProduct = Catalog::products()->create(new CreateProduct($kitType->id, 'duo-kit', $ru('Дуо набор')));
    $starterKit = Catalog::variants()->create(new CreateVariant($starterProduct->id, 'KIT-STARTER', weightGrams: 1000));
    $duoKit = Catalog::variants()->create(new CreateVariant($duoProduct->id, 'KIT-DUO', weightGrams: 900));
    Catalog::listings()->publish(new PublishProduct($starterProduct->id, $world->channel));
    Catalog::listings()->publish(new PublishProduct($duoProduct->id, $world->channel));

    Inventory::items()->link(new LinkSellable($starterKit->sellable, $world->smallItem, 2));
    Inventory::items()->link(new LinkSellable($starterKit->sellable, $world->mugItem, 1));
    Inventory::items()->link(new LinkSellable($duoKit->sellable, $world->smallItem, 2));
    Inventory::items()->link(new LinkSellable($duoKit->sellable, $world->mediumItem, 1));

    $kitPrices = Pricing::priceLists()->create(new CreatePriceList('kits', 'Kits', $rub, [$world->channel]));
    Pricing::prices()->set(new SetPrice($kitPrices->id, $starterKit->sellable, Money::of('2200.00', 'RUB')));
    Pricing::prices()->set(new SetPrice($kitPrices->id, $duoKit->sellable, Money::of('2600.00', 'RUB')));

    // Leave exactly 3 units of smallItem (enough for at most ONE kit requiring 2 units; second kit needs 4 > 3),
    // while companion components (mugItem, mediumItem) are plentiful.
    Inventory::stock()->adjust(new AdjustStock($world->smallItem, $world->location, -2, 'leave_3_for_one_kit'));
    Inventory::stock()->receive(new ReceiveStock($world->mediumItem, $world->location, 10));

    $carts = array_map(
        static fn (int $i): array => Checkout::cart(
            $world,
            [[$i % 2 === 0 ? $starterKit->sellable : $duoKit->sellable, 1]],
            "kit-stress-{$i}",
        ),
        range(0, 11),
    );
    $deadlocksBefore = Concurrency::pgDeadlocks();

    $outcomes = Concurrency::race(
        workers: 12,
        work: static function (int $worker) use ($carts): string {
            $kind = $worker % 2 === 0 ? 'starter' : 'duo';
            Checkout::place($carts[$worker]);

            return "ok:{$kind}";
        },
        expected: [
            InsufficientStock::class => 'insufficient_stock',
        ],
    );

    expect(Concurrency::unexpectedErrors($outcomes))->toBe([])
        ->and(Concurrency::deadlocksSince($deadlocksBefore))->toBe(0);

    $tally = Concurrency::tally($outcomes);
    $starterWins = $tally['ok:starter'] ?? 0;
    $duoWins = $tally['ok:duo'] ?? 0;

    $smallLevel = Inventory::stock()->level($world->smallItem, $world->location);
    $mugLevel = Inventory::stock()->level($world->mugItem, $world->location);
    $mediumLevel = Inventory::stock()->level($world->mediumItem, $world->location);

    // Exactly 1 kit sells across both kit variants; losing kit leaks ZERO reservations on its companion component.
    expect($starterWins + $duoWins)->toBe(1)
        ->and($tally['insufficient_stock'] ?? 0)->toBe(11)
        ->and([$smallLevel->onHand, $smallLevel->reserved, $smallLevel->available])->toBe([3, 2, 1])
        ->and($mugLevel->reserved)->toBe($starterWins)
        ->and($mediumLevel->reserved)->toBe($duoWins)
        ->and(Sales::orders()->list(new ListOrders)->items)->toHaveCount(1);

    stressAssertReconcilersClean();
})->group('concurrency');

it('2a: never breaches the last remaining single-use coupon across 12 concurrent checkouts', function (): void {
    $world = ShopWorld::build();
    Inventory::stock()->receive(new ReceiveStock($world->mugItem, $world->location, 20));

    $lastCouponPromo = Promotions::promotions()->create(new CreatePromotion(
        code: 'last-coupon-promo',
        name: 'Last Coupon -100',
        stage: PromotionStage::Cart,
        activator: 'coupon',
        channels: [$world->channel],
        effects: [new RuleSpec('order.fixed_off', ['amount' => Money::of('100.00', 'RUB')])],
        limits: new UsageLimits(total: 1, perCustomer: 1),
    ));
    Promotions::coupons()->create(new CreateCoupon($lastCouponPromo->id, 'LASTONE', usageLimit: 1));

    // All 12 carts are quoted while the single coupon use is still free.
    $carts = array_map(
        static fn (int $i): array => stressQuotedCart($world, [[$world->mug, 1]], "cpn-stress-{$i}", 'LASTONE'),
        range(0, 11),
    );
    $deadlocksBefore = Concurrency::pgDeadlocks();

    $outcomes = Concurrency::race(
        workers: 12,
        work: static function (int $worker) use ($carts): string {
            Checkout::place($carts[$worker]);

            return 'ok';
        },
        expected: [
            UsageLimitReached::class => 'limit_reached',
            QuoteChanged::class => 'quote_changed',
        ],
    );

    expect(Concurrency::unexpectedErrors($outcomes))->toBe([])
        ->and(Concurrency::deadlocksSince($deadlocksBefore))->toBe(0);

    $tally = Concurrency::tally($outcomes);
    $rejected = ($tally['limit_reached'] ?? 0) + ($tally['quote_changed'] ?? 0);
    $orders = Sales::orders()->list(new ListOrders)->items;
    $used = DB::table('cmz_coupons')->where('code', 'LASTONE')->value('used');
    if (! is_int($used) && ! is_string($used)) {
        throw new RuntimeException('Coupon used count is missing.');
    }

    expect($tally['ok'] ?? 0)->toBe(1)
        ->and($rejected)->toBe(11)
        ->and($orders)->toHaveCount(1)
        ->and($orders[0]->codes)->toBe(['LASTONE'])
        ->and(Promotions::promotions()->find($lastCouponPromo->id)->usage->orders)->toBe(1)
        ->and((int) $used)->toBe(1)
        ->and(Inventory::stock()->level($world->mugItem, $world->location)->reserved)->toBe(1);

    stressAssertReconcilersClean();
})->group('concurrency');

it('2b: never breaches CampaignBudget::spend_cap_minor across concurrent checkouts (INV-PRM-001)', function (): void {
    $world = ShopWorld::build();
    Inventory::stock()->receive(new ReceiveStock($world->mugItem, $world->location, 20));

    $campaign = Promotions::campaigns()->create(new CreateCampaign(
        code: 'stress-cap-300',
        name: 'Stress Campaign 300 RUB Cap',
        currency: Currency::of('RUB'),
        spendCap: Money::of('300.00', 'RUB'),
    ));

    $promoA = Promotions::promotions()->create(new CreatePromotion(
        code: 'camp-a-100',
        name: 'Campaign A -100',
        stage: PromotionStage::Cart,
        activator: 'coupon',
        channels: [$world->channel],
        effects: [new RuleSpec('order.fixed_off', ['amount' => Money::of('100.00', 'RUB')])],
        campaignId: $campaign->id,
    ));
    $promoB = Promotions::promotions()->create(new CreatePromotion(
        code: 'camp-b-100',
        name: 'Campaign B -100',
        stage: PromotionStage::Cart,
        activator: 'coupon',
        channels: [$world->channel],
        effects: [new RuleSpec('order.fixed_off', ['amount' => Money::of('100.00', 'RUB')])],
        campaignId: $campaign->id,
    ));
    Promotions::coupons()->create(new CreateCoupon($promoA->id, 'CAMPA'));
    Promotions::coupons()->create(new CreateCoupon($promoB->id, 'CAMPB'));

    // 14 carts alternating between two promotions sharing the same 300.00 RUB campaign spend cap.
    $carts = array_map(
        static fn (int $i): array => stressQuotedCart(
            $world,
            [[$world->mug, 1]],
            "camp-stress-{$i}",
            $i % 2 === 0 ? 'CAMPA' : 'CAMPB',
        ),
        range(0, 13),
    );
    $deadlocksBefore = Concurrency::pgDeadlocks();

    $outcomes = Concurrency::race(
        workers: 14,
        work: static function (int $worker) use ($carts): string {
            Checkout::place($carts[$worker]);

            return 'ok';
        },
        expected: [
            UsageLimitReached::class => 'limit_reached',
            QuoteChanged::class => 'quote_changed',
        ],
    );

    expect(Concurrency::unexpectedErrors($outcomes))->toBe([])
        ->and(Concurrency::deadlocksSince($deadlocksBefore))->toBe(0);

    $tally = Concurrency::tally($outcomes);
    $rejected = ($tally['limit_reached'] ?? 0) + ($tally['quote_changed'] ?? 0);
    $budget = Promotions::campaigns()->budget($campaign->id);

    expect($tally['ok'] ?? 0)->toBe(3)
        ->and($rejected)->toBe(11)
        ->and($budget->spentMinor)->toBe(30_000)
        ->and($budget->remainingSpendMinor())->toBe(0)
        ->and($budget->isExhausted())->toBeTrue()
        ->and(Sales::orders()->list(new ListOrders)->items)->toHaveCount(3)
        ->and(Inventory::stock()->level($world->mugItem, $world->location)->reserved)->toBe(3);

    stressAssertReconcilersClean();
})->group('concurrency');

it('3a: prevents concurrent double-spend of a GiftCard across 12 processes (INV-SV-001)', function (): void {
    $issued = StoredValue::giftCards()->issue(new IssueGiftCard(
        initialBalance: Money::of('100.00', 'RUB'),
    ));
    $accountId = $issued->giftCard->id->value;
    $deadlocksBefore = Concurrency::pgDeadlocks();

    // 12 concurrent processes each attempt to hold and capture 30.00 RUB against a 100.00 RUB gift card.
    $outcomes = Concurrency::race(
        workers: 12,
        work: static function (int $worker) use ($accountId): string {
            $hold = StoredValue::tender()->hold(new HoldStoredValue(
                accountId: $accountId,
                orderRef: "order:gc-stress-{$worker}",
                amount: Money::of('30.00', 'RUB'),
                idempotencyKey: "hold:gc-stress-{$worker}",
            ));

            StoredValue::tender()->capture(new CaptureStoredValue(
                holdId: $hold->holdId,
                reference: "order:gc-stress-{$worker}",
                idempotencyKey: "capture:gc-stress-{$worker}",
            ));

            return 'captured';
        },
        expected: [
            InsufficientStoredValue::class => 'insufficient',
        ],
    );

    Concurrency::assertRaceSettled($outcomes, ['captured' => 3, 'insufficient' => 9], $deadlocksBefore);

    $card = StoredValue::giftCards()->find($issued->giftCard->id);
    expect($card)->not->toBeNull();
    if ($card === null) {
        throw new RuntimeException('Gift card is missing.');
    }

    expect($card->balance->amountMinor)->toBe(1_000)
        ->and($card->held->amountMinor)->toBe(0)
        ->and($card->available->amountMinor)->toBe(1_000)
        ->and(StoredValue::ledger()->isBalanced($issued->giftCard->id))->toBeTrue();
})->group('concurrency');

it('3b: prevents concurrent double-spend of a CustomerWallet across 12 processes (INV-SV-001)', function (): void {
    $customer = CustomerRef::of('crm', 'wallet-stress-customer');
    $wallet = StoredValue::wallets()->getOrCreate($customer, Currency::of('RUB'));
    StoredValue::wallets()->credit(new CreditWallet(
        customer: $customer,
        amount: Money::of('100.00', 'RUB'),
        reference: 'credit:stress-init',
        idempotencyKey: 'credit:stress-init',
        entryType: LedgerEntryType::Issue,
    ));

    $accountId = $wallet->id->value;
    $deadlocksBefore = Concurrency::pgDeadlocks();

    // 12 concurrent processes each attempt to hold and capture 40.00 RUB against a 100.00 RUB wallet.
    $outcomes = Concurrency::race(
        workers: 12,
        work: static function (int $worker) use ($accountId): string {
            $hold = StoredValue::tender()->hold(new HoldStoredValue(
                accountId: $accountId,
                orderRef: "order:wallet-stress-{$worker}",
                amount: Money::of('40.00', 'RUB'),
                idempotencyKey: "hold:wallet-stress-{$worker}",
            ));

            StoredValue::tender()->capture(new CaptureStoredValue(
                holdId: $hold->holdId,
                reference: "order:wallet-stress-{$worker}",
                idempotencyKey: "capture:wallet-stress-{$worker}",
            ));

            return 'captured';
        },
        expected: [
            InsufficientStoredValue::class => 'insufficient',
        ],
    );

    Concurrency::assertRaceSettled($outcomes, ['captured' => 2, 'insufficient' => 10], $deadlocksBefore);

    $after = StoredValue::wallets()->find($wallet->id);
    expect($after)->not->toBeNull();
    if ($after === null) {
        throw new RuntimeException('Customer wallet is missing.');
    }

    expect($after->balance->amountMinor)->toBe(2_000)
        ->and($after->held->amountMinor)->toBe(0)
        ->and($after->available->amountMinor)->toBe(2_000)
        ->and(StoredValue::ledger()->isBalanced($wallet->id))->toBeTrue();
})->group('concurrency');

it('4: enforces OrderChange::confirm() OCC with 409 ORDERS_ORDER_CHANGE_VERSION_CONFLICT across 10 processes (INV-OMS-001)', function (): void {
    $world = ShopWorld::build();
    Inventory::stock()->receive(new ReceiveStock($world->mugItem, $world->location, 20));

    $order = Checkout::place(Checkout::cart($world, [[$world->small, 1]], 'occ-customer'));
    expect($order->version)->toBe(1);

    // Open 10 independent draft order changes anchored to order version 1.
    $changes = [];

    for ($i = 0; $i < 10; $i++) {
        $change = Sales::orderChanges()->create(new CreateOrderChange($order->id, "Concurrent edit #{$i}"));
        Sales::orderChanges()->addAction(new AddOrderChangeAction($change->id, new AddLineAction($world->mug, 1)));
        $changes[] = $change->id;
    }

    // Verify RFC 9457 Problem Definition registers 409 for OrderChangeVersionConflict.
    $definitions = (new SalesProblemDefinitions)->definitions();
    $conflictDef = array_values(array_filter(
        $definitions,
        static fn ($def): bool => $def->code === SalesErrorCode::OrderChangeVersionConflict,
    ));
    expect($conflictDef)->toHaveCount(1)
        ->and($conflictDef[0]->defaultStatus)->toBe(409);

    $deadlocksBefore = Concurrency::pgDeadlocks();

    $outcomes = Concurrency::race(
        workers: 10,
        work: static function (int $worker) use ($changes): string {
            Sales::orderChanges()->confirm($changes[$worker], expectedOrderVersion: 1);

            return 'confirmed';
        },
        expected: [
            OrderChangeVersionConflict::class => static function (Throwable $e): string {
                if (! $e instanceof OrderChangeVersionConflict) {
                    return 'error:unexpected_class';
                }

                $constants = new ReflectionClass($e);
                $domainCode = $constants->getConstant('DOMAIN_CODE');
                $invariant = $constants->getConstant('INVARIANT');
                $valid = $e->errorDescriptor()->category === ErrorCategory::Conflict
                    && $domainCode === 'ORDERS_ORDER_CHANGE_VERSION_CONFLICT'
                    && $invariant === 'INV-OMS-001'
                    && str_contains($e->getMessage(), 'ORDERS_ORDER_CHANGE_VERSION_CONFLICT')
                    && $e->expectedVersion === 1
                    && $e->actualVersion === 2;

                return $valid ? 'version_conflict_409' : 'error:invalid_conflict_payload';
            },
        ],
    );

    Concurrency::assertRaceSettled($outcomes, ['confirmed' => 1, 'version_conflict_409' => 9], $deadlocksBefore);

    $finalOrder = Sales::orders()->find($order->id);
    $allChanges = Sales::orderChanges()->listForOrder($order->id);
    $confirmed = array_values(array_filter($allChanges, static fn ($c): bool => $c->status === OrderChangeStatus::Confirmed));
    $drafts = array_values(array_filter($allChanges, static fn ($c): bool => $c->status === OrderChangeStatus::Draft));

    expect($finalOrder->version)->toBe(2)
        ->and($finalOrder->lines)->toHaveCount(2)
        ->and($finalOrder->total->amountMinor)->toBeGreaterThan($order->total->amountMinor)
        ->and($confirmed)->toHaveCount(1)
        ->and($confirmed[0]->confirmedOrderVersion)->toBe(2)
        ->and($drafts)->toHaveCount(9)
        ->and(DB::table('cmz_outbox_messages')->where('type', 'sales.order_change_confirmed')->count())->toBe(1)
        ->and(Inventory::stock()->level($world->smallItem, $world->location)->reserved)->toBe(1);

    stressAssertReconcilersClean();
})->group('concurrency');

it('5a: settles a timed-out payment via concurrent reconcile while blocking duplicate charges across 12 processes', function (): void {
    $world = ShopWorld::build();
    $order = Checkout::place(Checkout::cart($world, [[$world->small, 1]], 'pay-timeout-customer'));

    // Provider captures the money on its side, then the network response times out.
    StressPaymentGateway::configure('timeout_after_capture', sleepMs: 40);

    $paymentId = null;

    try {
        Sales::payments()->initiate(new InitiatePayment(
            order: $order->id,
            gateway: StressPaymentGateway::KEY,
            idempotencyKey: IdempotencyKey::of('initial-pay-attempt'),
        ));
    } catch (PaymentOutcomeUnknown $e) {
        $paymentId = new PaymentId($e->payment);
    }

    expect($paymentId)->not->toBeNull();
    if (! $paymentId instanceof PaymentId) {
        throw new RuntimeException('The timed-out payment id is missing.');
    }

    expect(Sales::payments()->find($paymentId)->status)->toBe(PaymentStatus::Unknown)
        ->and(StressPaymentGateway::initiateCalls())->toHaveCount(1)
        ->and(StressPaymentGateway::charges())->toHaveCount(1);

    // Switch gateway to normal capture for any stray initiate attempts (none may ever reach it).
    StressPaymentGateway::configure('capture', sleepMs: 60);
    $deadlocksBefore = Concurrency::pgDeadlocks();

    // Even workers (6) race reconcile() on the unknown payment; odd workers (6) race fresh initiate() calls
    // with distinct idempotency keys as impatient client retries.
    $outcomes = Concurrency::race(
        workers: 12,
        work: static function (int $worker) use ($order, $paymentId): string {
            if ($worker % 2 === 0) {
                $view = Sales::payments()->reconcile(new ReconcilePayment($paymentId));

                return 'reconcile:'.$view->status->value;
            }

            Sales::payments()->initiate(new InitiatePayment(
                order: $order->id,
                gateway: StressPaymentGateway::KEY,
                idempotencyKey: IdempotencyKey::of("retry-pay-{$worker}"),
            ));

            return 'duplicate_initiated';
        },
        expected: [
            PaymentExceedsBalance::class => 'exceeds_balance',
        ],
    );

    expect(Concurrency::unexpectedErrors($outcomes))->toBe([])
        ->and(Concurrency::deadlocksSince($deadlocksBefore))->toBe(0);

    $tally = Concurrency::tally($outcomes);
    $reconciledTotal = ($tally['reconcile:captured'] ?? 0) + ($tally['reconcile:unknown'] ?? 0);
    $finalPayment = Sales::payments()->find($paymentId);
    $finalOrder = Sales::orders()->find($order->id);

    // Every retry was blocked before hitting the gateway; the provider was charged strictly ONCE.
    expect($tally['duplicate_initiated'] ?? 0)->toBe(0)
        ->and($tally['exceeds_balance'] ?? 0)->toBe(6)
        ->and($reconciledTotal)->toBe(6)
        ->and($tally['reconcile:captured'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and($finalPayment->status)->toBe(PaymentStatus::Captured)
        ->and($finalPayment->captured)->toEqual($order->total)
        ->and($finalOrder->paymentState)->toBe(PaymentState::Paid)
        ->and($finalOrder->captured)->toEqual($order->total)
        ->and(StressPaymentGateway::initiateCalls())->toHaveCount(1)
        ->and(StressPaymentGateway::charges())->toHaveCount(1)
        ->and(DB::table('cmz_payments')->count())->toBe(1)
        ->and(DB::table('cmz_outbox_messages')->where('type', 'sales.payment_state_changed')->count())->toBe(1);
})->group('concurrency');

it('5b: reconciles a lost network timeout to failed and allows exactly one safe retry without double charge', function (): void {
    $world = ShopWorld::build();
    $order = Checkout::place(Checkout::cart($world, [[$world->small, 1]], 'pay-lost-timeout-customer'));

    // Network fails BEFORE the provider creates any charge.
    StressPaymentGateway::configure('timeout_before_capture', sleepMs: 20);

    $paymentId = null;

    try {
        Sales::payments()->initiate(new InitiatePayment(
            order: $order->id,
            gateway: StressPaymentGateway::KEY,
            idempotencyKey: IdempotencyKey::of('lost-attempt-1'),
        ));
    } catch (PaymentOutcomeUnknown $e) {
        $paymentId = new PaymentId($e->payment);
    }

    expect($paymentId)->not->toBeNull();
    if (! $paymentId instanceof PaymentId) {
        throw new RuntimeException('The timed-out payment id is missing.');
    }

    expect(Sales::payments()->find($paymentId)->status)->toBe(PaymentStatus::Unknown)
        ->and(StressPaymentGateway::charges())->toHaveCount(0);

    // 6 concurrent reconcile workers confirm the gateway never created the payment -> transitions to Failed.
    $deadlocksBefore = Concurrency::pgDeadlocks();

    $reconcileOutcomes = Concurrency::race(
        workers: 6,
        work: static function () use ($paymentId): string {
            $view = Sales::payments()->reconcile(new ReconcilePayment($paymentId));

            return 'reconcile:'.$view->status->value;
        },
    );

    expect(Concurrency::unexpectedErrors($reconcileOutcomes))->toBe([])
        ->and(Concurrency::deadlocksSince($deadlocksBefore))->toBe(0)
        ->and(Sales::payments()->find($paymentId)->status)->toBe(PaymentStatus::Failed)
        ->and(Sales::payments()->find($paymentId)->errorCode)->toBe('not_found_at_gateway');

    // Now the gateway is healthy: 6 concurrent retry workers race to pay the order; exactly ONE charges the card.
    StressPaymentGateway::configure('capture', sleepMs: 40);
    $deadlocksBeforeRetry = Concurrency::pgDeadlocks();

    $retryOutcomes = Concurrency::race(
        workers: 6,
        work: static function (int $worker) use ($order): string {
            $view = Sales::payments()->initiate(new InitiatePayment(
                order: $order->id,
                gateway: StressPaymentGateway::KEY,
                idempotencyKey: IdempotencyKey::of("safe-retry-{$worker}"),
            ));

            return $view->status->value;
        },
        expected: [
            PaymentExceedsBalance::class => 'exceeds_balance',
        ],
    );

    Concurrency::assertRaceSettled($retryOutcomes, ['captured' => 1, 'exceeds_balance' => 5], $deadlocksBeforeRetry);

    $finalOrder = Sales::orders()->find($order->id);

    expect($finalOrder->paymentState)->toBe(PaymentState::Paid)
        ->and($finalOrder->captured)->toEqual($order->total)
        ->and(StressPaymentGateway::charges())->toHaveCount(1)
        ->and(DB::table('cmz_payments')->where('status', 'captured')->count())->toBe(1)
        ->and(DB::table('cmz_payments')->where('status', 'failed')->count())->toBe(1);
})->group('concurrency');
