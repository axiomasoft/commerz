<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderStatus;
use Commerz\Sales\Configuration\SalesOptions;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Gateways\Data\GatewayAuthorizeRequest;
use Commerz\Sales\Gateways\Data\GatewayCaptureRequest;
use Commerz\Sales\Gateways\Data\GatewayIncrementalAuthRequest;
use Commerz\Sales\Gateways\Data\GatewayLineItem;
use Commerz\Sales\Gateways\Data\GatewayVoidRequest;
use Commerz\Sales\Gateways\Data\PaymentCapabilities;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Exceptions\GatewayCapabilityUnsupported;
use Commerz\Sales\Gateways\Internal\ManualGateway;
use Commerz\Sales\Payments\Internal\PaymentJournal;
use Commerz\Sales\Payments\Internal\PaymentWorkflow;
use Commerz\Sales\Tests\Fixtures\AcquiringRecordingGateway;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionResolverInterface;
use Mockery\MockInterface;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Schema\Contracts\Compiler\SchemaOwnership;
use Packman\Schema\Data\Compiler\TableNames as PackmanTables;

function buildWorkflowFixture(bool $inTransaction, PaymentGateway ...$gateways): PaymentWorkflow
{
    $map = [];
    foreach ($gateways as $gateway) {
        $map[$gateway->key()] = $gateway;
    }

    $transactions = new class($inTransaction) implements Transactions
    {
        public function __construct(private bool $active) {}

        public function run(callable $work, int $lockTimeoutMs = 3000): mixed
        {
            return $work();
        }

        public function inTransaction(): bool
        {
            return $this->active;
        }
    };

    $receipts = new class implements OperationReceipts
    {
        public function once(string $scope, IdempotencyKey $key, string $fingerprint, callable $work, callable $encode, callable $decode): mixed
        {
            return $work();
        }

        public function prune(DateTimeImmutable $olderThan): int
        {
            return 0;
        }
    };

    $contributions = new class($map) implements Contributions
    {
        /** @param array<string, PaymentGateway> $map */
        public function __construct(private array $map) {}

        public function has(string $kind, string $key): bool
        {
            return isset($this->map[$key]);
        }

        /**
         * @template T of object
         *
         * @param  class-string<T>  $expectedInterface
         * @return T
         */
        public function get(string $kind, string $key, string $expectedInterface): object
        {
            $gateway = $this->map[$key] ?? throw new RuntimeException("Unknown gateway {$key}");

            if (! $gateway instanceof $expectedInterface) {
                throw new RuntimeException("Gateway {$key} is not {$expectedInterface}.");
            }

            return $gateway;
        }

        /** @return list<string> */
        public function keys(string $kind): array
        {
            return array_keys($this->map);
        }

        /** @return list<PaymentGateway> */
        public function all(string $kind, string $expectedInterface): array
        {
            return array_values($this->map);
        }
    };

    /** @var ConnectionResolverInterface&MockInterface $resolver */
    $resolver = Mockery::mock(ConnectionResolverInterface::class);
    $options = new CommerzOptions;
    $ownership = new class implements SchemaOwnership
    {
        /** @param  list<string>  $nouns */
        public function declare(string $owner, array $nouns): void {}

        public function ownerOf(string $logical): string
        {
            return 'commerz.sales';
        }

        /** @return array<string, list<string>> */
        public function owners(): array
        {
            return ['commerz.sales' => ['order']];
        }
    };
    $tables = new TableNames(static fn (): PackmanTables => new PackmanTables('cmz_', $ownership, $resolver));
    /** @var CommittedEvents&MockInterface $events */
    $events = Mockery::mock(CommittedEvents::class);
    /** @var Outbox&MockInterface $outbox */
    $outbox = Mockery::mock(Outbox::class);
    $orderLocks = new OrderLocks($resolver, $options, $tables);
    $orderJournal = new OrderJournal($resolver, $options, $tables);
    $orderStatus = new OrderStatus($resolver, $options, $tables, $orderJournal, $outbox, $events);
    $paymentJournal = new PaymentJournal($resolver, $options, $tables, $transactions, $orderLocks, $orderJournal, $orderStatus, $events);

    return new PaymentWorkflow(
        $transactions,
        $receipts,
        $contributions,
        $orderLocks,
        $paymentJournal,
        new SalesOptions,
    );
}

it('executes multicapture (non-final then final), incremental auth, L3 line items, and void through PaymentWorkflow outside DB transactions', function (): void {
    AcquiringRecordingGateway::configure(new PaymentCapabilities(
        authorizeCaptureVoid: true,
        multicapture: true,
        incrementalAuth: true,
        overcapture: true,
        lineItemsL3: true,
    ));

    $workflow = buildWorkflowFixture(false, new ManualGateway, new AcquiringRecordingGateway);
    $l3 = [new GatewayLineItem('SKU-1', 'Item', 1, Money::of('100.00', 'USD'), Money::of('100.00', 'USD'))];

    $auth = $workflow->authorizeAtGateway(
        AcquiringRecordingGateway::KEY,
        new GatewayAuthorizeRequest('session:1', 'order:1', Money::of('100.00', 'USD'), 'ORD-1', lineItems: $l3),
        autoCapture: false,
    );
    expect($auth->status)->toBe(GatewayPaymentStatus::Authorized);

    $cap1 = $workflow->captureAtGateway(
        AcquiringRecordingGateway::KEY,
        new GatewayCaptureRequest('cap:1', 'session:1', $auth->externalId, Money::of('40.00', 'USD'), isFinalCapture: false, lineItems: $l3),
        isSubsequentCapture: false,
        isOvercapture: false,
    );
    expect($cap1->status)->toBe(GatewayPaymentStatus::Captured)
        ->and($cap1->isFinalCapture)->toBeFalse();

    $cap2 = $workflow->captureAtGateway(
        AcquiringRecordingGateway::KEY,
        new GatewayCaptureRequest('cap:2', 'session:1', $auth->externalId, Money::of('65.00', 'USD'), isFinalCapture: true),
        isSubsequentCapture: true,
        isOvercapture: true,
    );
    expect($cap2->status)->toBe(GatewayPaymentStatus::Captured)
        ->and($cap2->isFinalCapture)->toBeTrue();

    $inc = $workflow->incrementalAuthorizeAtGateway(
        AcquiringRecordingGateway::KEY,
        new GatewayIncrementalAuthRequest('inc:1', 'session:1', $auth->externalId, Money::of('20.00', 'USD'), Money::of('120.00', 'USD')),
    );
    expect($inc->authorized?->amountMinor)->toBe(12000);

    $void = $workflow->voidAtGateway(
        AcquiringRecordingGateway::KEY,
        new GatewayVoidRequest('void:1', 'session:1', $auth->externalId, 'cancelled'),
    );
    expect($void->status)->toBe(GatewayPaymentStatus::Cancelled)
        ->and(array_column(AcquiringRecordingGateway::calls(), 'op'))
        ->toBe(['authorize', 'capture', 'capture', 'incremental_authorize', 'void']);
});

it('enforces capability gates and D33 no-network-inside-transaction invariant', function (): void {
    AcquiringRecordingGateway::configure(new PaymentCapabilities(
        authorizeCaptureVoid: true,
        multicapture: false,
        incrementalAuth: false,
        overcapture: false,
        lineItemsL3: false,
    ));

    $workflow = buildWorkflowFixture(false, new ManualGateway, new AcquiringRecordingGateway);
    $l3 = [new GatewayLineItem('SKU-1', 'Item', 1, Money::of('10.00', 'USD'), Money::of('10.00', 'USD'))];

    expect(fn () => $workflow->validateCapabilityForAuthorize('manual', autoCapture: false, hasLineItems: false))
        ->toThrow(GatewayCapabilityUnsupported::class, 'authorizeCaptureVoid')
        ->and(fn () => $workflow->validateCapabilityForAuthorize(AcquiringRecordingGateway::KEY, autoCapture: false, hasLineItems: true))
        ->toThrow(GatewayCapabilityUnsupported::class, 'lineItemsL3')
        ->and(fn () => $workflow->validateCapabilityForCapture(AcquiringRecordingGateway::KEY, isFinalCapture: false, isSubsequentCapture: false, hasLineItems: false))
        ->toThrow(GatewayCapabilityUnsupported::class, 'multicapture')
        ->and(fn () => $workflow->validateCapabilityForIncrementalAuth(AcquiringRecordingGateway::KEY))
        ->toThrow(GatewayCapabilityUnsupported::class, 'incrementalAuth')
        ->and(fn () => $workflow->captureAtGateway(
            AcquiringRecordingGateway::KEY,
            new GatewayCaptureRequest('cap:1', 'session:1', 'ext-1', Money::of('110.00', 'USD'), isFinalCapture: true),
            isSubsequentCapture: false,
            isOvercapture: true,
        ))->toThrow(GatewayCapabilityUnsupported::class, 'overcapture');

    // D33: every gateway acquiring call MUST refuse to run inside an ambient DB transaction.
    $txWorkflow = buildWorkflowFixture(true, new AcquiringRecordingGateway);

    expect(fn () => $txWorkflow->authorizeAtGateway(
        AcquiringRecordingGateway::KEY,
        new GatewayAuthorizeRequest('session:1', 'order:1', Money::of('10.00', 'USD')),
        autoCapture: false,
    ))->toThrow(OperationInsideTransaction::class)
        ->and(fn () => $txWorkflow->captureAtGateway(
            AcquiringRecordingGateway::KEY,
            new GatewayCaptureRequest('cap:1', 'session:1', 'ext-1', Money::of('10.00', 'USD')),
            isSubsequentCapture: false,
            isOvercapture: false,
        ))->toThrow(OperationInsideTransaction::class)
        ->and(fn () => $txWorkflow->incrementalAuthorizeAtGateway(
            AcquiringRecordingGateway::KEY,
            new GatewayIncrementalAuthRequest('inc:1', 'session:1', 'ext-1', Money::of('5.00', 'USD'), Money::of('15.00', 'USD')),
        ))->toThrow(OperationInsideTransaction::class)
        ->and(fn () => $txWorkflow->voidAtGateway(
            AcquiringRecordingGateway::KEY,
            new GatewayVoidRequest('void:1', 'session:1', 'ext-1'),
        ))->toThrow(OperationInsideTransaction::class);
});
