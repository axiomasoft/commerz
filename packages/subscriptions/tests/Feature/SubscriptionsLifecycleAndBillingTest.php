<?php

declare(strict_types=1);

use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\Rate;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Sellable\BalanceDueTrigger;
use Commerz\Sellable\PricingPolicy;
use Commerz\Sellable\SellingPlan;
use Commerz\Sellable\SellingPlanInterval;
use Commerz\Stores\Data\ChannelId;
use Commerz\Subscriptions\Billing\Data\RetryPolicy;
use Commerz\Subscriptions\Billing\Enums\BillingCycleStatus;
use Commerz\Subscriptions\Billing\Enums\RetryExhaustedAction;
use Commerz\Subscriptions\Errors\Enums\SubscriptionsErrorCode;
use Commerz\Subscriptions\Errors\Exceptions\SubscriptionException;
use Commerz\Subscriptions\Facades\Subscriptions;
use Commerz\Subscriptions\Lifecycle\Data\CreateSubscriptionContract;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionLineInput;
use Commerz\Subscriptions\Lifecycle\Enums\SubscriptionStatus;
use Commerz\Subscriptions\Renewals\Contracts\RenewalOrderCreator;
use Commerz\Subscriptions\Renewals\Data\PlaceRenewalOrder;
use Commerz\Subscriptions\Renewals\Data\RenewalOrderResult;
use Commerz\Subscriptions\Renewals\Exceptions\RenewalPaymentFailed;
use Commerz\Subscriptions\Renewals\Internal\DatabaseRenewalOrders;
use Commerz\Testkit\Fakes\FakePrices;
use Commerz\Testkit\Fakes\FakeSellables;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Packman\Plugins\Contracts\Contributions\Contributions;

beforeEach(function (): void {
    config()->set('database.connections.sub_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('commerz.connection', 'sub_sqlite');
    DB::purge('sub_sqlite');

    $db = DB::connection('sub_sqlite');
    $db->statement('PRAGMA foreign_keys = ON');

    $db->statement(<<<'SQL'
        create table cmz_outbox_messages (
            id integer primary key autoincrement,
            event_id text not null unique,
            type text not null,
            version integer not null default 1,
            aggregate_ref text not null,
            payload text not null,
            occurred_at text not null,
            available_at text not null default current_timestamp,
            delivered_at text,
            attempts integer not null default 0,
            last_error text
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_subscription_contracts (
            id text primary key,
            channel_id text not null,
            currency text not null,
            locale text not null default 'en',
            customer_ref text not null,
            status text not null default 'active' check (status in ('active', 'paused', 'past_due', 'canceled')),
            payment_method_ref text,
            origin_order_ref text,
            origin_line_id text,
            idempotency_key text unique,
            selling_plan text not null,
            retry_policy text not null,
            completed_cycles integer not null default 0 check (completed_cycles >= 0),
            consecutive_failures integer not null default 0 check (consecutive_failures >= 0),
            next_billing_at text,
            paused_at text,
            canceled_at text,
            cancel_reason text,
            version integer not null default 0 check (version >= 0),
            metadata text not null default '{}',
            created_at text not null,
            updated_at text not null,
            unique (origin_order_ref, origin_line_id)
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_subscription_contract_lines (
            id text primary key,
            contract_id text not null references cmz_subscription_contracts(id) on delete cascade,
            line_id text not null,
            sellable_ref text not null,
            sku text not null,
            name text not null,
            kind text not null,
            requires_shipping integer not null default 1,
            quantity integer not null check (quantity >= 1),
            base_unit_price_minor integer not null check (base_unit_price_minor >= 0),
            tax_class text,
            weight_grams integer,
            snapshot text not null default '{}',
            created_at text not null,
            updated_at text not null,
            unique (contract_id, line_id)
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_subscription_billing_cycles (
            id text primary key,
            contract_id text not null references cmz_subscription_contracts(id),
            cycle_number integer not null check (cycle_number >= 1),
            status text not null default 'scheduled' check (status in ('scheduled', 'retrying', 'billed', 'skipped', 'canceled', 'failed')),
            scheduled_at text not null,
            next_attempt_at text,
            attempts integer not null default 0 check (attempts >= 0),
            currency text not null,
            billed_amount_minor integer check (billed_amount_minor is null or billed_amount_minor >= 0),
            order_ref text,
            last_error text,
            billed_at text,
            created_at text not null,
            updated_at text not null,
            unique (contract_id, cycle_number)
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_subscription_renewal_orders (
            id text primary key,
            order_ref text not null unique,
            contract_id text not null references cmz_subscription_contracts(id),
            cycle_id text not null unique references cmz_subscription_billing_cycles(id),
            cycle_number integer not null check (cycle_number >= 2),
            channel_id text not null,
            customer_ref text not null,
            payment_method_ref text,
            currency text not null,
            subtotal_minor integer not null,
            discount_total_minor integer not null,
            delivery_total_minor integer not null,
            tax_total_minor integer not null,
            total_minor integer not null check (total_minor >= 0),
            due_now_minor integer not null check (due_now_minor >= 0),
            idempotency_key text not null unique,
            lines_snapshot text not null,
            created_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_subscription_processed_events (
            id text primary key,
            event_id text not null unique,
            event_type text not null,
            aggregate_ref text not null,
            processed_at text not null
        )
    SQL);

    app()->singleton(Transactions::class, static fn (): Transactions => new class($db) implements Transactions
    {
        public function __construct(private readonly ConnectionInterface $db) {}

        public function run(callable $work, int $lockTimeoutMs = 3000): mixed
        {
            return $this->db->transaction(static fn (): mixed => $work());
        }

        public function inTransaction(): bool
        {
            return $this->db->transactionLevel() > 0;
        }
    });

    app()->singleton(PriceResolver::class, static fn (): FakePrices => new FakePrices);
    app()->singleton(Clock::class, static fn (): Clock => new FrozenClock(new DateTimeImmutable('2026-11-26T10:00:00Z')));
});

it('ingests orders.order_placed from Outbox for SellingPlan::Recurring and automatically creates the 2nd billing cycle order', function (): void {
    /** @var FrozenClock $clock */
    $clock = app(Clock::class);
    $channelId = ChannelId::generate();
    $customer = CustomerRef::of('customer', '42');

    // Introductory 20% off Cycle 1, then 10% off Cycle 2+
    $recurringPlan = SellingPlan::recurring(
        id: 'coffee-club-monthly',
        name: 'Coffee Club Monthly',
        interval: SellingPlanInterval::Month,
        intervalCount: 1,
        pricingPolicy: PricingPolicy::percentageOff(Rate::percent('20'))
            ->withAfterCycle(1, PricingPolicy::percentageOff(Rate::percent('10'))),
    );

    $depositPlan = SellingPlan::deposit(
        id: 'roaster-deposit',
        name: '20% Deposit',
        depositRate: Rate::percent('20'),
        balanceDueTrigger: BalanceDueTrigger::BeforeShipment,
    );

    /** @var OutboxConsumer $consumer */
    $consumer = app(Contributions::class)->get(OutboxConsumer::KIND, 'subscriptions.order_placed', OutboxConsumer::class);

    $initialEvent = IntegrationEvent::of(
        'orders.order_placed',
        'order:initial-1001',
        [
            'order_ref' => 'order:initial-1001',
            'channel_id' => $channelId->value,
            'currency' => 'USD',
            'locale' => 'en',
            'customer_ref' => (string) $customer,
            'payment_method_ref' => 'pm:visa_saved_99',
            'lines' => [
                [
                    'line_id' => 'line-sub',
                    'sellable_ref' => 'variant:coffee-1kg',
                    'sku' => 'COFFEE-1KG',
                    'name' => 'Single Origin Coffee 1kg',
                    'kind' => 'physical',
                    'requires_shipping' => true,
                    'quantity' => 2,
                    'base_unit_price_minor' => 5000, // $50.00 base -> $40.00 cycle 1 (x2 = $80.00), $45.00 cycle 2 (x2 = $90.00)
                    'total_minor' => 8000,
                    'selling_plan' => $recurringPlan->toArray(),
                ],
                [
                    // Non-recurring deposit line in the same order must NOT spawn a subscription contract
                    'line_id' => 'line-deposit',
                    'sellable_ref' => 'variant:machine',
                    'sku' => 'ROASTER-PRO',
                    'name' => 'Roaster Machine',
                    'kind' => 'physical',
                    'requires_shipping' => true,
                    'quantity' => 1,
                    'base_unit_price_minor' => 20000,
                    'selling_plan' => $depositPlan->toArray(),
                ],
            ],
        ],
        occurredAt: $clock->now(),
    );

    $consumer->consume($initialEvent);
    // At-least-once Outbox replay of same event_id is idempotent
    $consumer->consume($initialEvent);

    $contracts = Subscriptions::contracts()->forCustomer($customer);
    expect($contracts)->toHaveCount(1);

    $contract = $contracts[0];
    expect($contract->status)->toBe(SubscriptionStatus::Active)
        ->and($contract->completedCycles)->toBe(1)
        ->and($contract->originOrderRef)->toBe('order:initial-1001')
        ->and($contract->nextBillingAt?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-12-26T10:00:00Z');

    $cyclesBefore = Subscriptions::billing()->forContract($contract->id);
    expect($cyclesBefore)->toHaveCount(2)
        ->and($cyclesBefore[0]->cycleNumber)->toBe(1)
        ->and($cyclesBefore[0]->status)->toBe(BillingCycleStatus::Billed)
        ->and($cyclesBefore[0]->orderRef)->toBe('order:initial-1001')
        ->and($cyclesBefore[0]->billedAmount)->toEqual(Money::of('80.00', 'USD'))
        ->and($cyclesBefore[1]->cycleNumber)->toBe(2)
        ->and($cyclesBefore[1]->status)->toBe(BillingCycleStatus::Scheduled)
        ->and($cyclesBefore[1]->scheduledAt->format('Y-m-d\TH:i:s\Z'))->toBe('2026-12-26T10:00:00Z');

    // Before due date: nothing processed
    expect(Subscriptions::billing()->processDue()->processed)->toBe(0);

    // Advance clock to Cycle #2 due date (2026-12-26) and run billing via Artisan command
    $clock->set(new DateTimeImmutable('2026-12-26T10:05:00Z'));
    $this->artisan('commerz:subscriptions:bill')->assertSuccessful();

    $renewals = Subscriptions::renewals()->forContract($contract->id);
    expect($renewals)->toHaveCount(1);

    $secondCycleOrder = $renewals[0];
    // Cycle 2 uses afterCycle(1) 10% discount: $50.00 - 10% = $45.00 × 2 = $90.00
    expect($secondCycleOrder->cycleNumber)->toBe(2)
        ->and($secondCycleOrder->total)->toEqual(Money::of('90.00', 'USD'))
        ->and($secondCycleOrder->dueNowAmount)->toEqual(Money::of('90.00', 'USD'))
        ->and($secondCycleOrder->paymentMethodRef)->toBe('pm:visa_saved_99');

    $contractAfter = Subscriptions::contracts()->get($contract->id);
    $cyclesAfter = Subscriptions::billing()->forContract($contract->id);

    expect($contractAfter->status)->toBe(SubscriptionStatus::Active)
        ->and($contractAfter->completedCycles)->toBe(2)
        ->and($contractAfter->nextBillingAt?->format('Y-m-d\TH:i:s\Z'))->toBe('2027-01-26T10:00:00Z')
        ->and($cyclesAfter)->toHaveCount(3)
        ->and($cyclesAfter[1]->status)->toBe(BillingCycleStatus::Billed)
        ->and($cyclesAfter[1]->orderRef)->toBe($secondCycleOrder->orderRef)
        ->and($cyclesAfter[1]->billedAmount)->toEqual(Money::of('90.00', 'USD'))
        ->and($cyclesAfter[2]->cycleNumber)->toBe(3)
        ->and($cyclesAfter[2]->status)->toBe(BillingCycleStatus::Scheduled)
        ->and($cyclesAfter[2]->scheduledAt->format('Y-m-d\TH:i:s\Z'))->toBe('2027-01-26T10:00:00Z');

    $outboxTypes = DB::connection('sub_sqlite')
        ->table('cmz_outbox_messages')
        ->pluck('type')
        ->all();

    expect($outboxTypes)->toContain(
        'subscriptions.contract_created',
        'subscriptions.renewal_order_created',
        'subscriptions.cycle_billed',
    );
});

it('manages SubscriptionContract lifecycle transitions (active, paused, past_due, canceled) and enforces dunning RetryPolicy', function (): void {
    /** @var FrozenClock $clock */
    $clock = app(Clock::class);

    $plan = SellingPlan::recurring(
        id: 'vitamins-weekly',
        name: 'Weekly Vitamins',
        interval: SellingPlanInterval::Week,
        intervalCount: 1,
    );

    $contract = Subscriptions::contracts()->create(new CreateSubscriptionContract(
        channelId: ChannelId::generate(),
        currency: Currency::of('EUR'),
        customer: CustomerRef::of('customer', '77'),
        sellingPlan: $plan,
        lines: [
            new SubscriptionLineInput(
                'line-1',
                FakeSellables::snapshot('product:vitamins', Money::of('25.00', 'EUR')),
                1,
            ),
        ],
        paymentMethodRef: 'pm:card_declining',
        originOrderRef: 'order:origin-77',
        originLineId: 'line-1',
        retryPolicy: new RetryPolicy(
            maxAttempts: 3,
            backoffDelaysSeconds: [3_600, 7_200],
            exhaustedAction: RetryExhaustedAction::Cancel,
        ),
    ));

    // Pause blocks due billing; resume restores active status
    $paused = Subscriptions::contracts()->pause($contract->id, 'customer_vacation');
    expect($paused->status)->toBe(SubscriptionStatus::Paused)
        ->and($paused->nextBillingAt)->toBeNull();

    $clock->set(new DateTimeImmutable('2026-12-03T10:00:00Z'));
    expect(Subscriptions::billing()->processDue()->processed)->toBe(0);

    $resumed = Subscriptions::contracts()->resume($contract->id);
    expect($resumed->status)->toBe(SubscriptionStatus::Active)
        ->and($resumed->nextBillingAt?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-12-03T10:00:00Z');

    // Bind a RenewalOrderCreator that fails when paymentMethodRef is 'pm:card_declining' and delegates otherwise
    $realCreator = app(DatabaseRenewalOrders::class);
    app()->singleton(RenewalOrderCreator::class, static fn (): RenewalOrderCreator => new class($realCreator) implements RenewalOrderCreator
    {
        public function __construct(private readonly DatabaseRenewalOrders $inner) {}

        public function placeRenewalOrder(PlaceRenewalOrder $command): RenewalOrderResult
        {
            if ($command->paymentMethodRef === 'pm:card_declining') {
                throw new RenewalPaymentFailed($command->contractId->value, $command->cycleId->value, 'insufficient_funds');
            }

            return $this->inner->placeRenewalOrder($command);
        }
    });

    // Attempt 1 fails -> contract becomes past_due, cycle enters retrying (+1h)
    $report1 = Subscriptions::billing()->processDue();
    expect($report1->processed)->toBe(1)
        ->and($report1->retrying)->toBe(1)
        ->and($report1->billed)->toBe(0);

    $pastDue = Subscriptions::contracts()->get($contract->id);
    expect($pastDue->status)->toBe(SubscriptionStatus::PastDue)
        ->and($pastDue->consecutiveFailures)->toBe(1)
        ->and($pastDue->nextBillingAt?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-12-03T11:00:00Z');

    // Customer updates payment method; +1h later retry succeeds and recovers contract to active!
    Subscriptions::contracts()->updatePaymentMethod($contract->id, 'pm:card_valid');
    $clock->set(new DateTimeImmutable('2026-12-03T11:00:00Z'));

    $report2 = Subscriptions::billing()->processDue();
    expect($report2->billed)->toBe(1);

    $recovered = Subscriptions::contracts()->get($contract->id);
    expect($recovered->status)->toBe(SubscriptionStatus::Active)
        ->and($recovered->consecutiveFailures)->toBe(0)
        ->and($recovered->completedCycles)->toBe(2);

    // Switch back to declining card for Cycle #3 and exhaust all 3 attempts -> contract auto-cancels!
    Subscriptions::contracts()->updatePaymentMethod($contract->id, 'pm:card_declining');

    $clock->set(new DateTimeImmutable('2026-12-10T10:00:00Z'));
    expect(Subscriptions::billing()->processDue()->retrying)->toBe(1); // Attempt 1

    $clock->set(new DateTimeImmutable('2026-12-10T11:00:00Z'));
    expect(Subscriptions::billing()->processDue()->retrying)->toBe(1); // Attempt 2

    $clock->set(new DateTimeImmutable('2026-12-10T13:00:00Z'));
    $exhaustedReport = Subscriptions::billing()->processDue(); // Attempt 3 -> exhausted!
    expect($exhaustedReport->exhausted)->toBe(1);

    $canceled = Subscriptions::contracts()->get($contract->id);
    expect($canceled->status)->toBe(SubscriptionStatus::Canceled)
        ->and($canceled->cancelReason)->toBe('billing_retries_exhausted')
        ->and($canceled->nextBillingAt)->toBeNull();

    // Terminal canceled contract rejects resume
    expect(function () use ($contract): void {
        Subscriptions::contracts()->resume($contract->id);
    })->toThrow(SubscriptionException::class);

    try {
        Subscriptions::contracts()->pause($contract->id);
    } catch (SubscriptionException $e) {
        expect($e->errorCode)->toBe(SubscriptionsErrorCode::ContractCanceled);
    }
});
