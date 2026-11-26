<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Feature\Payments;

use Commerz\CommerzServiceProvider;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\Uuid7;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\Gateways\Data\GatewayLineItem;
use Commerz\Sales\Gateways\Data\PaymentCapabilities;
use Commerz\Sales\Gateways\Exceptions\GatewayCapabilityUnsupported;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Contracts\PaymentCollections;
use Commerz\Sales\Payments\Data\AddStoredValueSession;
use Commerz\Sales\Payments\Data\AuthorizeGatewaySession;
use Commerz\Sales\Payments\Data\CancelPaymentCollection;
use Commerz\Sales\Payments\Data\CapturePaymentSession;
use Commerz\Sales\Payments\Data\CreatePaymentCollection;
use Commerz\Sales\Payments\Data\IncrementalAuthorizeSession;
use Commerz\Sales\Payments\Data\VoidPaymentSession;
use Commerz\Sales\Payments\Enums\PaymentCaptureStatus;
use Commerz\Sales\Payments\Enums\PaymentCollectionKind;
use Commerz\Sales\Payments\Enums\PaymentCollectionStatus;
use Commerz\Sales\Payments\Enums\PaymentSessionKind;
use Commerz\Sales\Payments\Enums\PaymentSessionStatus;
use Commerz\Sales\Payments\Events\PaymentCollectionStatusChanged;
use Commerz\Sales\Payments\Exceptions\CaptureExceedsAuthorization;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentSessionState;
use Commerz\Sales\Payments\Exceptions\PaymentExceedsBalance;
use Commerz\Sales\SalesServiceProvider;
use Commerz\Sales\Tests\Fixtures\AcquiringRecordingGateway;
use Commerz\Sales\Tests\Fixtures\InMemoryStoredValueTender;
use Commerz\Sales\Tests\Fixtures\SalesFixturePlugin;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use DateTimeImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end SQLite-backed feature test for {@see PaymentCollections} exercising real persistence,
 * split tender with {@see StoredValueTender}, multicapture (`isFinalCapture: false -> true`),
 * incremental authorization, overcapture, Level 3 line items, void/cancel, idempotency, and D33 guards.
 */
final class PaymentCollectionsEndToEndTest extends TestCase
{
    private InMemoryStoredValueTender $storedValue;

    protected function getPackageProviders($app): array
    {
        return [CommerzServiceProvider::class, SalesServiceProvider::class];
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.sqlite_payments', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('commerz.connection', 'sqlite_payments');
        $app['config']->set('commerz.plugins', [SalesFixturePlugin::class]);
        $app['config']->set('commerz.stock', 'unlimited');
    }

    protected function setUp(): void
    {
        parent::setUp();

        AcquiringRecordingGateway::reset();
        $this->storedValue = new InMemoryStoredValueTender;

        $this->app->instance(Clock::class, new FrozenClock(new DateTimeImmutable('2026-11-26 12:00:00+00:00')));
        $this->app->instance(StoredValueTender::class, $this->storedValue);
        $this->app->instance(Transactions::class, new class implements Transactions
        {
            public function run(callable $work, int $lockTimeoutMs = 3000): mixed
            {
                return DB::connection('sqlite_payments')->transaction(static fn () => $work());
            }

            public function inTransaction(): bool
            {
                return DB::connection('sqlite_payments')->transactionLevel() > 0;
            }
        });
        $this->app->instance(OperationReceipts::class, new class implements OperationReceipts
        {
            /** @var array<string, array{fingerprint: string, payload: array<string, mixed>}> */
            private array $store = [];

            public function once(string $scope, IdempotencyKey $key, string $fingerprint, callable $work, callable $encode, callable $decode): mixed
            {
                $slot = $scope.'::'.$key->value;

                if (isset($this->store[$slot])) {
                    if ($this->store[$slot]['fingerprint'] !== $fingerprint) {
                        throw new IdempotencyConflict($scope, $key->value);
                    }

                    return $decode($this->store[$slot]['payload']);
                }

                $result = $work();
                $this->store[$slot] = ['fingerprint' => $fingerprint, 'payload' => $encode($result)];

                return $result;
            }

            public function prune(DateTimeImmutable $olderThan): int
            {
                return 0;
            }
        });
        $this->app->forgetInstance(PaymentCollections::class);

        $schema = Schema::connection('sqlite_payments');

        $schema->create('cmz_orders', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('number', 64);
            $table->string('state', 16);
            $table->char('currency', 3);
        });

        $schema->create('cmz_payment_collections', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 16);
            $table->string('target_ref', 191);
            $table->uuid('order_id')->nullable();
            $table->string('status', 32);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('authorized_minor')->default(0);
            $table->bigInteger('captured_minor')->default(0);
            $table->string('customer_ref', 191)->nullable();
            $table->string('customer_email', 191)->nullable();
            $table->text('meta')->default('{}');
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });

        $schema->create('cmz_payment_sessions', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('collection_id');
            $table->string('kind', 16);
            $table->string('gateway', 64)->nullable();
            $table->string('stored_value_kind', 16)->nullable();
            $table->string('stored_value_account_id', 191)->nullable();
            $table->string('stored_value_hold_id', 191)->nullable();
            $table->string('reference', 191)->unique();
            $table->string('external_id', 191)->nullable();
            $table->string('status', 32);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('authorized_minor')->default(0);
            $table->bigInteger('captured_minor')->default(0);
            $table->boolean('capture_closed')->default(false);
            $table->text('action_url')->nullable();
            $table->text('meta')->default('{}');
            $table->char('fingerprint', 64);
            $table->integer('attempts')->default(1);
            $table->string('claimed_at', 64);
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });

        $schema->create('cmz_payment_captures', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->uuid('collection_id');
            $table->string('reference', 191)->unique();
            $table->string('external_id', 191)->nullable();
            $table->string('status', 16);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->boolean('is_final_capture')->default(true);
            $table->text('meta')->default('{}');
            $table->char('fingerprint', 64);
            $table->integer('attempts')->default(1);
            $table->string('claimed_at', 64);
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
        });
    }

    #[Test]
    public function split_tender_with_stored_value_and_card_multicapture_transitions_through_full_lifecycle(): void
    {
        Event::fake([PaymentCollectionStatusChanged::class]);

        $orderId = new OrderId(Uuid7::generate());
        DB::connection('sqlite_payments')->table('cmz_orders')->insert([
            'id' => $orderId->value,
            'number' => 'ORD-5001',
            'state' => 'placed',
            'currency' => 'USD',
        ]);

        $this->storedValue->seed('gc-1', Money::of('30.00', 'USD'));
        $collections = Sales::paymentCollections();

        // 1. Create collection for order (100.00 USD)
        $collection = $collections->create(CreatePaymentCollection::forOrder(
            order: $orderId,
            amount: Money::of('100.00', 'USD'),
            idempotencyKey: IdempotencyKey::of('col-1'),
        ));

        // Idempotent repeat returns same collection
        $repeated = $collections->create(CreatePaymentCollection::forOrder(
            order: $orderId,
            amount: Money::of('100.00', 'USD'),
            idempotencyKey: IdempotencyKey::of('col-1'),
        ));
        self::assertTrue($collection->id->equals($repeated->id));
        self::assertSame(PaymentCollectionStatus::NotPaid, $collection->status);

        // 2. StoredValue gift card hold (30.00) -> PartiallyAuthorized
        $collection = $collections->addStoredValueSession(new AddStoredValueSession(
            collection: $collection->id,
            storedValueKind: StoredValueKind::GiftCard,
            accountId: 'gc-1',
            amount: Money::of('30.00', 'USD'),
            captureImmediately: false,
            idempotencyKey: IdempotencyKey::of('sv-hold-1'),
        ));

        self::assertSame(PaymentCollectionStatus::PartiallyAuthorized, $collection->status);
        self::assertEquals(Money::of('30.00', 'USD'), $collection->authorized);
        self::assertEquals(Money::of('70.00', 'USD'), $collection->remainingToAuthorize());
        self::assertEquals(Money::of('30.00', 'USD'), $this->storedValue->heldOf('gc-1'));

        // 3. Card gateway authorize for remaining 70.00 with L3 line items -> Authorized
        $l3 = [new GatewayLineItem('SKU-1', 'Pump', 1, Money::of('100.00', 'USD'), Money::of('100.00', 'USD'))];
        $collection = $collections->authorizeGatewaySession(new AuthorizeGatewaySession(
            collection: $collection->id,
            gateway: AcquiringRecordingGateway::KEY,
            amount: null,
            autoCapture: false,
            lineItems: $l3,
            idempotencyKey: IdempotencyKey::of('gw-auth-1'),
        ));

        // Replay with same key must not call gateway a second time
        $collections->authorizeGatewaySession(new AuthorizeGatewaySession(
            collection: $collection->id,
            gateway: AcquiringRecordingGateway::KEY,
            amount: null,
            autoCapture: false,
            lineItems: $l3,
            idempotencyKey: IdempotencyKey::of('gw-auth-1'),
        ));
        self::assertCount(1, AcquiringRecordingGateway::calls());
        self::assertSame(1, AcquiringRecordingGateway::calls()[0]['line_items']);
        self::assertSame(PaymentCollectionStatus::Authorized, $collection->status);
        self::assertEquals(Money::of('100.00', 'USD'), $collection->authorized);
        self::assertCount(2, $collection->sessions);

        $svSession = $collection->sessions[0];
        $cardSession = $collection->sessions[1];
        self::assertSame(PaymentSessionKind::StoredValue, $svSession->kind);
        self::assertSame(PaymentSessionKind::Gateway, $cardSession->kind);
        self::assertEquals(Money::of('70.00', 'USD'), $cardSession->amount);

        // 4. Capture StoredValue 30.00 -> PartiallyCaptured
        $collection = $collections->captureSession(new CapturePaymentSession(
            session: $svSession->id,
            amount: Money::of('30.00', 'USD'),
            isFinalCapture: true,
        ));
        self::assertSame(PaymentCollectionStatus::PartiallyCaptured, $collection->status);
        self::assertEquals(Money::of('30.00', 'USD'), $collection->captured);
        self::assertEquals(Money::of('0.00', 'USD'), $this->storedValue->balanceOf('gc-1'));
        self::assertEquals(Money::of('0.00', 'USD'), $this->storedValue->heldOf('gc-1'));

        // 5. Multicapture #1: capture 40.00 with isFinalCapture: false -> session stays PartiallyCaptured & open
        $collection = $collections->captureSession(new CapturePaymentSession(
            session: $cardSession->id,
            amount: Money::of('40.00', 'USD'),
            isFinalCapture: false,
            lineItems: $l3,
            idempotencyKey: IdempotencyKey::of('cap-1'),
        ));

        $midCard = $collection->session($cardSession->id);
        self::assertNotNull($midCard);
        self::assertSame(PaymentCollectionStatus::PartiallyCaptured, $collection->status);
        self::assertEquals(Money::of('70.00', 'USD'), $collection->captured);
        self::assertEquals(Money::of('30.00', 'USD'), $collection->authorized);
        self::assertSame(PaymentSessionStatus::PartiallyCaptured, $midCard->status);
        self::assertFalse($midCard->captureClosed);
        self::assertEquals(Money::of('30.00', 'USD'), $midCard->remainingCapturable());
        self::assertCount(1, $midCard->captures);
        self::assertFalse($midCard->captures[0]->isFinalCapture);
        self::assertSame(PaymentCaptureStatus::Succeeded, $midCard->captures[0]->status);

        // 6. Multicapture #2: capture 30.00 with isFinalCapture: true -> session & collection Captured!
        $collection = $collections->captureSession(new CapturePaymentSession(
            session: $cardSession->id,
            amount: Money::of('30.00', 'USD'),
            isFinalCapture: true,
            idempotencyKey: IdempotencyKey::of('cap-2'),
        ));

        $finalCard = $collection->session($cardSession->id);
        self::assertNotNull($finalCard);
        self::assertSame(PaymentCollectionStatus::Captured, $collection->status);
        self::assertEquals(Money::of('100.00', 'USD'), $collection->captured);
        self::assertEquals(Money::of('0.00', 'USD'), $collection->authorized);
        self::assertSame(PaymentSessionStatus::Captured, $finalCard->status);
        self::assertTrue($finalCard->captureClosed);
        self::assertCount(2, $finalCard->captures);
        self::assertTrue($finalCard->captures[1]->isFinalCapture);

        // 7. Subsequent capture after final capture is rejected
        $this->expectException(InvalidPaymentSessionState::class);
        $collections->captureSession(new CapturePaymentSession($cardSession->id, Money::of('1.00', 'USD')));
    }

    #[Test]
    public function supports_incremental_authorization_overcapture_top_up_void_cancel_and_d33_guards(): void
    {
        $collections = Sales::paymentCollections();
        $this->storedValue->seed('wallet-1', Money::of('50.00', 'EUR'));

        // Top-up collection
        $collection = $collections->create(CreatePaymentCollection::forTopUp(
            targetRef: 'topup:wallet-1',
            amount: Money::of('100.00', 'EUR'),
        ));
        self::assertSame(PaymentCollectionKind::TopUp, $collection->kind);
        self::assertNull($collection->order);
        self::assertCount(1, $collections->forTarget('topup:wallet-1'));

        // Authorize 100.00
        $collection = $collections->authorizeGatewaySession(new AuthorizeGatewaySession(
            collection: $collection->id,
            gateway: AcquiringRecordingGateway::KEY,
            amount: Money::of('100.00', 'EUR'),
        ));
        $session = $collection->sessions[0];

        // Exceeding balance on new session fails
        try {
            $collections->authorizeGatewaySession(new AuthorizeGatewaySession(
                collection: $collection->id,
                gateway: AcquiringRecordingGateway::KEY,
                amount: Money::of('1.00', 'EUR'),
            ));
            self::fail('Expected PaymentExceedsBalance');
        } catch (PaymentExceedsBalance) {
        }

        // Incremental authorization +25.00 -> 125.00 authorized
        $collection = $collections->incrementalAuthorize(new IncrementalAuthorizeSession(
            session: $session->id,
            additionalAmount: Money::of('25.00', 'EUR'),
            idempotencyKey: IdempotencyKey::of('inc-1'),
        ));
        self::assertEquals(Money::of('125.00', 'EUR'), $collection->session($session->id)?->authorized);

        // Overcapture rejected when capability is disabled
        AcquiringRecordingGateway::configure(new PaymentCapabilities(
            authorizeCaptureVoid: true,
            multicapture: true,
            incrementalAuth: true,
            overcapture: false,
            lineItemsL3: false,
        ));

        try {
            $collections->captureSession(new CapturePaymentSession($session->id, Money::of('130.00', 'EUR'), isFinalCapture: true));
            self::fail('Expected CaptureExceedsAuthorization');
        } catch (CaptureExceedsAuthorization) {
        }

        // Re-enable overcapture and capture 130.00 (> 125.00 auth)
        AcquiringRecordingGateway::configure(new PaymentCapabilities(
            authorizeCaptureVoid: true,
            multicapture: true,
            incrementalAuth: true,
            overcapture: true,
            lineItemsL3: true,
        ));
        $captured = $collections->captureSession(new CapturePaymentSession($session->id, Money::of('130.00', 'EUR'), isFinalCapture: true));
        self::assertSame(PaymentCollectionStatus::Captured, $captured->status);
        self::assertEquals(Money::of('130.00', 'EUR'), $captured->captured);

        // Cannot cancel captured collection
        try {
            $collections->cancel(new CancelPaymentCollection($captured->id));
            self::fail('Expected InvalidPaymentSessionState');
        } catch (InvalidPaymentSessionState) {
        }

        // Void & cancel flow with both StoredValue and Gateway sessions
        $col2 = $collections->create(CreatePaymentCollection::forTopUp('topup:cancel-1', Money::of('80.00', 'EUR')));
        $col2 = $collections->addStoredValueSession(new AddStoredValueSession($col2->id, StoredValueKind::Wallet, 'wallet-1', Money::of('30.00', 'EUR')));
        $col2 = $collections->authorizeGatewaySession(new AuthorizeGatewaySession($col2->id, AcquiringRecordingGateway::KEY, Money::of('50.00', 'EUR')));

        self::assertEquals(Money::of('30.00', 'EUR'), $this->storedValue->heldOf('wallet-1'));

        // D33: gateway call inside ambient DB transaction MUST throw OperationInsideTransaction
        $callsBefore = count(AcquiringRecordingGateway::calls());

        try {
            app(Transactions::class)->run(fn () => $collections->voidSession(new VoidPaymentSession($col2->sessions[1]->id)));
            self::fail('Expected OperationInsideTransaction');
        } catch (OperationInsideTransaction) {
        }
        self::assertCount($callsBefore, AcquiringRecordingGateway::calls());

        // Cancel voids both stored-value hold and card authorization cleanly
        $cancelled = $collections->cancel(new CancelPaymentCollection($col2->id, 'abandoned'));
        self::assertSame(PaymentCollectionStatus::Cancelled, $cancelled->status);
        self::assertEquals(Money::of('0.00', 'EUR'), $this->storedValue->heldOf('wallet-1'));
        self::assertSame(PaymentSessionStatus::Voided, $cancelled->sessions[0]->status);
        self::assertSame(PaymentSessionStatus::Voided, $cancelled->sessions[1]->status);

        // Declined gateway auth marks session Failed and releases balance so another session can succeed
        $col3 = $collections->create(CreatePaymentCollection::forTopUp('topup:decline-1', Money::of('40.00', 'EUR')));
        AcquiringRecordingGateway::configure(scenario: ['authorize_fail' => 'decline']);

        try {
            $collections->authorizeGatewaySession(new AuthorizeGatewaySession($col3->id, AcquiringRecordingGateway::KEY));
            self::fail('Expected PaymentDeclined');
        } catch (PaymentDeclined) {
        }

        $afterDecline = $collections->find($col3->id);
        self::assertSame(PaymentSessionStatus::Failed, $afterDecline->sessions[0]->status);
        self::assertEquals(Money::of('40.00', 'EUR'), $afterDecline->remainingToAuthorize());

        // Basic non-acquiring gateway is refused for explicit authorize
        try {
            $collections->authorizeGatewaySession(new AuthorizeGatewaySession($col3->id, 'manual', autoCapture: false));
            self::fail('Expected GatewayCapabilityUnsupported');
        } catch (GatewayCapabilityUnsupported) {
        }
    }
}
