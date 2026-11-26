<?php

declare(strict_types=1);

use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Data\CaptureStoredValue;
use Commerz\Ports\StoredValue\Data\HoldStoredValue;
use Commerz\Ports\StoredValue\Data\RefundToStoredValue;
use Commerz\Ports\StoredValue\Data\ReleaseStoredValue;
use Commerz\Ports\StoredValue\Data\StoredValueCapture;
use Commerz\Ports\StoredValue\Data\StoredValueCredit;
use Commerz\Ports\StoredValue\Data\StoredValueHold;
use Commerz\Ports\StoredValue\Data\TenderSettlement;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayRefundResult;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Enums\GatewayRefundStatus;
use Commerz\Sales\OrderChanges\Contracts\OrderChangeCompensator;
use Commerz\Sales\OrderChanges\Contracts\OrderChanges;
use Commerz\Sales\OrderChanges\Data\AddLineAction;
use Commerz\Sales\OrderChanges\Data\AddOrderChangeAction;
use Commerz\Sales\OrderChanges\Data\ChangeLineQuantityAction;
use Commerz\Sales\OrderChanges\Data\ChangeShippingMethodAction;
use Commerz\Sales\OrderChanges\Data\CompensationPreference;
use Commerz\Sales\OrderChanges\Data\CreateOrderChange;
use Commerz\Sales\OrderChanges\Data\OverrideUnitPriceAction;
use Commerz\Sales\OrderChanges\Data\RemoveLineAction;
use Commerz\Sales\OrderChanges\Data\ReplaceAdjustmentsAction;
use Commerz\Sales\OrderChanges\Enums\CompensationStrategy;
use Commerz\Sales\OrderChanges\Enums\NegativeDeltaStrategy;
use Commerz\Sales\OrderChanges\Enums\OrderChangeStatus;
use Commerz\Sales\OrderChanges\Enums\PositiveDeltaStrategy;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeVersionConflict;
use Commerz\Sales\Tests\Fixtures\RecordingGateway;
use Commerz\Sales\Tests\Fixtures\StaticSellables;

uses(SlsEnvironment::class);

beforeEach(function (): void {
    slsCommitted();
    StaticSellables::reset();
    StaticSellables::put(slsSnapshot('variant:a', '100.00'));
    StaticSellables::put(slsSnapshot('variant:b', '50.00'));
    StaticSellables::put(slsSnapshot('variant:c', '80.00'));
});

afterEach(function (): void {
    slsTruncate();
});

it('previews dry-run without DB mutation and confirms add/remove line changes with tax & amountDelta reconciliation', function (): void {
    $channel = slsChannel();
    $cart = slsCartWith($channel, ['variant:a' => 2, 'variant:b' => 1]);
    $cart = slsCarts()->enterCode(new EnterCode($cart->id, 'vat'));
    $cart = slsCarts()->selectDelivery(new SelectDelivery($cart->id, 'flat'));
    $order = slsPlace($cart);

    // VatStage adds 1.00 tax per line + 2.00 on delivery; FlatDeliveryStage charges 15.00.
    // Before: 200 + 50 + 15 delivery + (1 + 1 + 2) tax = 269.00, version = 1.
    expect($order->version)->toBe(1)
        ->and($order->totals->taxTotal)->toEqual(Money::of('4.00', 'RUB'))
        ->and($order->total)->toEqual(Money::of('269.00', 'RUB'));

    $change = slsOrderChanges()->create(new CreateOrderChange($order->id, 'Swap line B for line C and change quantity'));
    $change = slsOrderChanges()->addAction(new AddOrderChangeAction($change->id, new AddLineAction(SellableRef::of('variant', 'c'), 1)));
    $change = slsOrderChanges()->addAction(new AddOrderChangeAction($change->id, new RemoveLineAction(slsLine($order, 'variant:b'))));
    $change = slsOrderChanges()->addAction(new AddOrderChangeAction($change->id, new ChangeLineQuantityAction(slsLine($order, 'variant:a'), 3)));
    $change = slsOrderChanges()->addAction(new AddOrderChangeAction($change->id, new OverrideUnitPriceAction(slsLine($order, 'variant:a'), Money::of('90.00', 'RUB'))));
    $change = slsOrderChanges()->addAction(new AddOrderChangeAction($change->id, new ReplaceAdjustmentsAction([
        Adjustment::order(Money::of('-10.00', 'RUB'), 'manual', 'Adjustment'),
    ])));

    // Dry-run preview does not touch the order in PostgreSQL.
    $preview = slsOrderChanges()->preview($change->id);
    $reloaded = slsOrders()->find($order->id);

    // After: line A (3 x 90 = 270) + line C (1 x 80 = 80) - 10 discount + 15 delivery + 4 tax = 359.00 (delta = +90.00).
    expect($preview->excerptBefore->totals->total)->toEqual(Money::of('269.00', 'RUB'))
        ->and($preview->excerptAfter->totals->total)->toEqual(Money::of('359.00', 'RUB'))
        ->and($preview->amountDelta)->toEqual(Money::of('90.00', 'RUB'))
        ->and($preview->taxDelta)->toEqual(Money::zero('RUB'))
        ->and($reloaded->version)->toBe(1)
        ->and($reloaded->total)->toEqual(Money::of('269.00', 'RUB'))
        ->and($reloaded->lines)->toHaveCount(2);

    // Confirm with matching expectedOrderVersion = 1.
    $confirmed = slsOrderChanges()->confirm($change->id, expectedOrderVersion: 1);

    expect($confirmed->change->status)->toBe(OrderChangeStatus::Confirmed)
        ->and($confirmed->change->confirmedOrderVersion)->toBe(2)
        ->and($confirmed->order->version)->toBe(2)
        ->and($confirmed->order->total)->toEqual(Money::of('359.00', 'RUB'))
        ->and($confirmed->order->amountDue)->toEqual(Money::of('359.00', 'RUB'))
        ->and($confirmed->order->lines)->toHaveCount(2);
});

it('rejects stale expectedOrderVersion on confirm with 409 ORDERS_ORDER_CHANGE_VERSION_CONFLICT (INV-OMS-001)', function (): void {
    $channel = slsChannel();
    $cart = slsCartWith($channel, ['variant:a' => 1, 'variant:b' => 1]);
    $cart = slsCarts()->selectDelivery(new SelectDelivery($cart->id, 'flat'));
    $order = slsPlace($cart);

    $change1 = slsOrderChanges()->create(new CreateOrderChange($order->id, 'First edit'));
    slsOrderChanges()->addAction(new AddOrderChangeAction($change1->id, new ChangeLineQuantityAction(slsLine($order, 'variant:a'), 2)));

    $change2 = slsOrderChanges()->create(new CreateOrderChange($order->id, 'Conflicting concurrent edit'));
    slsOrderChanges()->addAction(new AddOrderChangeAction($change2->id, new RemoveLineAction(slsLine($order, 'variant:b'))));

    $confirmed = slsOrderChanges()->confirm($change1->id, expectedOrderVersion: 1);
    expect($confirmed->order->version)->toBe(2);

    // Second change still expects version 1 -> MUST throw 409 OrderChangeVersionConflict (INV-OMS-001).
    expect(fn () => slsOrderChanges()->confirm($change2->id, expectedOrderVersion: 1))
        ->toThrow(OrderChangeVersionConflict::class, 'ORDERS_ORDER_CHANGE_VERSION_CONFLICT');
});

it('executes compensating delta charge on positive delta and partial refund or wallet credit on negative delta', function (): void {
    $channel = slsChannel();
    $cart = slsCartWith($channel, ['variant:a' => 2, 'variant:b' => 1]);
    $cart = slsCarts()->setCustomer(new SetCartCustomer($cart->id, CustomerRef::of('user', '99')));
    $cart = slsCarts()->selectDelivery(new SelectDelivery($cart->id, 'flat'));
    $order = slsPlace($cart); // 200 + 50 + 15 = 265.00

    RecordingGateway::$onInitiate = static fn ($req): GatewayPaymentResult => new GatewayPaymentResult(
        status: GatewayPaymentStatus::Captured,
        externalId: 'pay-'.uniqid(),
        captured: $req->amount,
    );
    RecordingGateway::$onRefund = static fn ($req): GatewayRefundResult => new GatewayRefundResult(
        status: GatewayRefundStatus::Succeeded,
        externalId: 'ref-'.uniqid(),
    );

    slsPay($order, 'recording');
    $paid = slsOrders()->find($order->id);

    // 1. Positive delta (+80.00): initiates a delta charge via RecordingGateway.
    $increase = slsOrderChanges()->create(new CreateOrderChange($paid->id, 'Add line C'));
    slsOrderChanges()->addAction(new AddOrderChangeAction($increase->id, new AddLineAction(SellableRef::of('variant', 'c'), 1)));

    $incResult = slsOrderChanges()->confirm(
        $increase->id,
        expectedOrderVersion: $paid->version,
        compensation: new CompensationPreference(onIncrease: PositiveDeltaStrategy::DeltaCharge, paymentGateway: 'recording'),
    );
    expect($incResult->compensation->strategy)->toBe(CompensationStrategy::DeltaCharge)
        ->and($incResult->compensation->amount)->toEqual(Money::of('80.00', 'RUB'))
        ->and($incResult->compensation->paymentId)->not->toBeNull();

    // 2. Negative delta (-50.00): issues a partial refund via Refunds.
    $afterIncrease = slsOrders()->find($paid->id);
    $decrease = slsOrderChanges()->create(new CreateOrderChange($afterIncrease->id, 'Remove line B'));
    slsOrderChanges()->addAction(new AddOrderChangeAction($decrease->id, new RemoveLineAction(slsLine($afterIncrease, 'variant:b'))));

    $decResult = slsOrderChanges()->confirm(
        $decrease->id,
        expectedOrderVersion: $afterIncrease->version,
        compensation: new CompensationPreference(onDecrease: NegativeDeltaStrategy::PartialRefund),
    );
    expect($decResult->compensation->strategy)->toBe(CompensationStrategy::PartialRefund)
        ->and($decResult->compensation->amount)->toEqual(Money::of('50.00', 'RUB'))
        ->and($decResult->compensation->refundId)->not->toBeNull();

    // 3. Negative delta (-15.00 via clearing shipping method): credits CustomerWallet via StoredValueTender.
    $credited = [];
    app()->instance(StoredValueTender::class, new class($credited) implements StoredValueTender
    {
        /** @param  list<RefundToStoredValue>  $credited */
        public function __construct(public array &$credited) {}

        public function applyToQuote(QuoteResult $quote, array $tenders): TenderSettlement
        {
            throw new LogicException('Unused');
        }

        public function hold(HoldStoredValue $command): StoredValueHold
        {
            throw new LogicException('Unused');
        }

        public function capture(CaptureStoredValue $command): StoredValueCapture
        {
            throw new LogicException('Unused');
        }

        public function release(ReleaseStoredValue $command): void {}

        public function refundCredit(RefundToStoredValue $command): StoredValueCredit
        {
            $this->credited[] = $command;

            return new StoredValueCredit('wallet-99', $command->amount, $command->amount, 'jrn-wallet-1');
        }
    });

    // Forget cached OrderChanges/OrderChangeCompensator so the newly bound StoredValueTender fake is resolved.
    app()->forgetInstance(OrderChanges::class);
    app()->forgetInstance(OrderChangeCompensator::class);

    $afterRefund = slsOrders()->find($paid->id);
    $walletChange = slsOrderChanges()->create(new CreateOrderChange($afterRefund->id, 'Waive shipping'));
    slsOrderChanges()->addAction(new AddOrderChangeAction($walletChange->id, new ChangeShippingMethodAction(null)));

    $walletResult = slsOrderChanges()->confirm(
        $walletChange->id,
        expectedOrderVersion: $afterRefund->version,
        compensation: new CompensationPreference(onDecrease: NegativeDeltaStrategy::WalletCredit),
    );

    expect($walletResult->compensation->strategy)->toBe(CompensationStrategy::WalletCredit)
        ->and($walletResult->compensation->amount)->toEqual(Money::of('15.00', 'RUB'))
        ->and($walletResult->compensation->storedValueAccountId)->toBe('wallet-99')
        ->and($credited)->toHaveCount(1);
});
