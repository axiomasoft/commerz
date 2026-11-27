<?php

declare(strict_types=1);

use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\Gateways\Data\GatewayLineItem;
use Commerz\Sales\Gateways\Data\PaymentCapabilities;
use Commerz\Sales\Gateways\Exceptions\GatewayCapabilityUnsupported;
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
use Commerz\Sales\Payments\Exceptions\CaptureExceedsAuthorization;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentSessionState;
use Commerz\Sales\Tests\Fixtures\AcquiringRecordingGateway;
use Commerz\Sales\Tests\Fixtures\InMemoryStoredValueTender;
use Commerz\Testkit\Commerce;

require_once __DIR__.'/../Helpers.php';

uses(SlsEnvironment::class);

beforeEach(function (): void {
    slsCommitted();
    AcquiringRecordingGateway::reset();
    $this->storedValue = new InMemoryStoredValueTender;
    app()->instance(StoredValueTender::class, $this->storedValue);
    app()->forgetInstance(PaymentCollections::class);
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(slsSnapshot('fake:a', '50.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
});

afterEach(fn () => slsTruncate());

it('combines StoredValueTender and card gateway in one order with multicapture (non-final then final)', function (): void {
    $order = slsPlace(slsCartWith(slsChannel(), ['fake:a' => 2])); // 100.00 RUB
    $this->storedValue->seed('gc-account-1', Money::of('30.00', 'RUB'));

    $collections = Sales::paymentCollections();

    // 1. Open collection for the placed order (D11: placement already committed locally)
    $collection = $collections->create(CreatePaymentCollection::forOrder(
        order: $order->id,
        amount: $order->amountDue,
        idempotencyKey: IdempotencyKey::of('col-order-1'),
    ));

    expect($collection->status)->toBe(PaymentCollectionStatus::NotPaid)
        ->and($collection->remainingToAuthorize())->toEqual(Money::of('100.00', 'RUB'));

    // 2. Apply StoredValue gift card hold for 30.00 -> PartiallyAuthorized
    $collection = $collections->addStoredValueSession(new AddStoredValueSession(
        collection: $collection->id,
        storedValueKind: StoredValueKind::GiftCard,
        accountId: 'gc-account-1',
        amount: Money::of('30.00', 'RUB'),
        captureImmediately: false,
        idempotencyKey: IdempotencyKey::of('sv-1'),
    ));

    expect($collection->status)->toBe(PaymentCollectionStatus::PartiallyAuthorized)
        ->and($collection->authorized)->toEqual(Money::of('30.00', 'RUB'))
        ->and($collection->remainingToAuthorize())->toEqual(Money::of('70.00', 'RUB'))
        ->and($this->storedValue->heldOf('gc-account-1'))->toEqual(Money::of('30.00', 'RUB'));

    // 3. Authorize card gateway for exact remaining balance (70.00) with L3 line items -> Authorized
    $l3 = [new GatewayLineItem('SKU-A', 'Item A', 2, Money::of('50.00', 'RUB'), Money::of('100.00', 'RUB'))];
    $collection = $collections->authorizeGatewaySession(new AuthorizeGatewaySession(
        collection: $collection->id,
        gateway: AcquiringRecordingGateway::KEY,
        autoCapture: false,
        lineItems: $l3,
        idempotencyKey: IdempotencyKey::of('card-auth-1'),
    ));

    expect($collection->status)->toBe(PaymentCollectionStatus::Authorized)
        ->and($collection->authorized)->toEqual(Money::of('100.00', 'RUB'))
        ->and($collection->remainingToAuthorize())->toEqual(Money::of('0.00', 'RUB'))
        ->and($collection->sessions)->toHaveCount(2);

    $svSession = $collection->sessions[0];
    $cardSession = $collection->sessions[1];

    expect($svSession->kind)->toBe(PaymentSessionKind::StoredValue)
        ->and($cardSession->kind)->toBe(PaymentSessionKind::Gateway)
        ->and($cardSession->amount)->toEqual(Money::of('70.00', 'RUB'));

    // 4. Capture StoredValue 30.00 -> PartiallyCaptured
    $collection = $collections->captureSession(new CapturePaymentSession(
        session: $svSession->id,
        amount: Money::of('30.00', 'RUB'),
        isFinalCapture: true,
        idempotencyKey: IdempotencyKey::of('sv-cap-1'),
    ));

    expect($collection->status)->toBe(PaymentCollectionStatus::PartiallyCaptured)
        ->and($collection->captured)->toEqual(Money::of('30.00', 'RUB'))
        ->and($this->storedValue->balanceOf('gc-account-1'))->toEqual(Money::of('0.00', 'RUB'))
        ->and($this->storedValue->heldOf('gc-account-1'))->toEqual(Money::of('0.00', 'RUB'));

    // 5. Multicapture Card step 1: capture 40.00 with isFinalCapture: false -> keeps remaining 30.00 open!
    $collection = $collections->captureSession(new CapturePaymentSession(
        session: $cardSession->id,
        amount: Money::of('40.00', 'RUB'),
        isFinalCapture: false,
        lineItems: $l3,
        idempotencyKey: IdempotencyKey::of('card-cap-1'),
    ));

    $updatedCard = $collection->session($cardSession->id);
    expect($collection->status)->toBe(PaymentCollectionStatus::PartiallyCaptured)
        ->and($collection->captured)->toEqual(Money::of('70.00', 'RUB'))
        ->and($collection->authorized)->toEqual(Money::of('30.00', 'RUB'))
        ->and($updatedCard?->status)->toBe(PaymentSessionStatus::PartiallyCaptured)
        ->and($updatedCard?->captureClosed)->toBeFalse()
        ->and($updatedCard?->remainingCapturable())->toEqual(Money::of('30.00', 'RUB'))
        ->and($updatedCard?->captures)->toHaveCount(1)
        ->and($updatedCard?->captures[0]->isFinalCapture)->toBeFalse()
        ->and($updatedCard?->captures[0]->status)->toBe(PaymentCaptureStatus::Succeeded);

    // 6. Multicapture Card step 2: capture remaining 30.00 with isFinalCapture: true -> Captured!
    $collection = $collections->captureSession(new CapturePaymentSession(
        session: $cardSession->id,
        amount: Money::of('30.00', 'RUB'),
        isFinalCapture: true,
        idempotencyKey: IdempotencyKey::of('card-cap-2'),
    ));

    $finalCard = $collection->session($cardSession->id);
    expect($collection->status)->toBe(PaymentCollectionStatus::Captured)
        ->and($collection->captured)->toEqual(Money::of('100.00', 'RUB'))
        ->and($collection->authorized)->toEqual(Money::of('0.00', 'RUB'))
        ->and($finalCard?->status)->toBe(PaymentSessionStatus::Captured)
        ->and($finalCard?->captureClosed)->toBeTrue()
        ->and($finalCard?->captures)->toHaveCount(2)
        ->and($finalCard?->captures[1]->isFinalCapture)->toBeTrue();

    // 7. A 3rd capture after final capture is refused!
    expect(fn () => $collections->captureSession(new CapturePaymentSession($cardSession->id, Money::of('1.00', 'RUB'))))
        ->toThrow(InvalidPaymentSessionState::class);
});

it('supports incremental authorization, overcapture gating, top-up collections, void/cancel, and D33 transaction guards', function (): void {
    $collections = Sales::paymentCollections();
    $this->storedValue->seed('wallet-1', Money::of('25.00', 'USD'));

    // Top-up collection without an OrderId
    $collection = $collections->create(CreatePaymentCollection::forTopUp(
        targetRef: 'topup:wallet-1',
        amount: Money::of('100.00', 'USD'),
    ));

    expect($collection->kind)->toBe(PaymentCollectionKind::TopUp)
        ->and($collection->order)->toBeNull();

    // Authorize 100.00 at gateway
    $collection = $collections->authorizeGatewaySession(new AuthorizeGatewaySession(
        collection: $collection->id,
        gateway: AcquiringRecordingGateway::KEY,
        amount: Money::of('100.00', 'USD'),
        autoCapture: false,
    ));
    $session = $collection->sessions[0];

    // Incremental authorization +20.00 -> 120.00 authorized
    $collection = $collections->incrementalAuthorize(new IncrementalAuthorizeSession(
        session: $session->id,
        additionalAmount: Money::of('20.00', 'USD'),
        idempotencyKey: IdempotencyKey::of('inc-1'),
    ));
    expect($collection->session($session->id)?->authorized)->toEqual(Money::of('120.00', 'USD'));

    // Overcapture rejected when capability is disabled
    AcquiringRecordingGateway::configure(new PaymentCapabilities(
        authorizeCaptureVoid: true,
        multicapture: true,
        incrementalAuth: true,
        overcapture: false,
        lineItemsL3: false,
    ));

    expect(fn () => $collections->captureSession(new CapturePaymentSession(
        session: $session->id,
        amount: Money::of('125.00', 'USD'),
        isFinalCapture: true,
    )))->toThrow(CaptureExceedsAuthorization::class);

    // D33: gateway calls fail inside ambient DB transaction
    expect(fn () => app(Transactions::class)->run(fn () => $collections->captureSession(new CapturePaymentSession($session->id, Money::of('10.00', 'USD')))))
        ->toThrow(OperationInsideTransaction::class);

    // Cancel voids gateway auth cleanly
    $cancelled = $collections->cancel(new CancelPaymentCollection($collection->id, 'customer_abandoned'));
    expect($cancelled->status)->toBe(PaymentCollectionStatus::Cancelled)
        ->and($cancelled->session($session->id)?->status)->toBe(PaymentSessionStatus::Voided);

    // Non-capable manual gateway rejected for separate authorize
    $col2 = $collections->create(CreatePaymentCollection::forTopUp('topup:2', Money::of('10.00', 'USD')));
    expect(fn () => $collections->authorizeGatewaySession(new AuthorizeGatewaySession($col2->id, 'manual', autoCapture: false)))
        ->toThrow(GatewayCapabilityUnsupported::class, 'authorizeCaptureVoid');

    // StoredValue hold + void releases hold cleanly
    $col2 = $collections->addStoredValueSession(new AddStoredValueSession($col2->id, StoredValueKind::Wallet, 'wallet-1', Money::of('10.00', 'USD')));
    expect($this->storedValue->heldOf('wallet-1'))->toEqual(Money::of('10.00', 'USD'));

    $col2 = $collections->voidSession(new VoidPaymentSession($col2->sessions[0]->id, 'released'));
    expect($this->storedValue->heldOf('wallet-1'))->toEqual(Money::of('0.00', 'USD'))
        ->and($col2->status)->toBe(PaymentCollectionStatus::NotPaid);
});
