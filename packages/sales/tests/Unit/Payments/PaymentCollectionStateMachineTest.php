<?php

declare(strict_types=1);

use Commerz\Money\Currency;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\Sales\Payments\Data\PaymentCollectionId;
use Commerz\Sales\Payments\Data\PaymentSessionId;
use Commerz\Sales\Payments\Enums\PaymentCollectionStatus as CS;
use Commerz\Sales\Payments\Enums\PaymentSessionKind;
use Commerz\Sales\Payments\Enums\PaymentSessionStatus as SS;
use Commerz\Sales\Payments\Internal\PaymentCollectionStateMachine;
use Commerz\Sales\Payments\Internal\PaymentSessionRow;

function smSession(
    PaymentSessionKind $kind,
    SS $status,
    int $amount,
    int $authorized = 0,
    int $captured = 0,
    bool $captureClosed = false,
): PaymentSessionRow {
    $id = '0190a000-0000-7000-8000-0000000000'.random_int(10, 99);

    return new PaymentSessionRow(
        id: new PaymentSessionId($id),
        collection: new PaymentCollectionId('0190a000-0000-7000-8000-000000000001'),
        kind: $kind,
        gateway: $kind === PaymentSessionKind::Gateway ? 'acquiring.test' : null,
        storedValueKind: $kind === PaymentSessionKind::StoredValue ? StoredValueKind::GiftCard : null,
        storedValueAccountId: $kind === PaymentSessionKind::StoredValue ? 'gc-1' : null,
        storedValueHoldId: $kind === PaymentSessionKind::StoredValue ? 'hold-1' : null,
        reference: 'session:'.$id,
        externalId: null,
        status: $status,
        currency: Currency::of('USD'),
        amount: $amount,
        authorized: $authorized,
        captured: $captured,
        captureClosed: $captureClosed,
    );
}

it('derives split-tender collection states across stored value and gateway multicapture sessions', function (): void {
    // 1. Empty collection -> NotPaid
    expect(PaymentCollectionStateMachine::derive(10000, []))->toBe([
        'status' => CS::NotPaid,
        'authorized' => 0,
        'captured' => 0,
        'committed' => 0,
    ]);

    // 2. StoredValue hold for 30.00 out of 100.00 -> PartiallyAuthorized
    $svHold = smSession(PaymentSessionKind::StoredValue, SS::Authorized, 3000, authorized: 3000);
    expect(PaymentCollectionStateMachine::derive(10000, [$svHold]))->toBe([
        'status' => CS::PartiallyAuthorized,
        'authorized' => 3000,
        'captured' => 0,
        'committed' => 3000,
    ]);

    // 3. Add Card gateway auth for remaining 70.00 -> Authorized
    $cardAuth = smSession(PaymentSessionKind::Gateway, SS::Authorized, 7000, authorized: 7000);
    expect(PaymentCollectionStateMachine::derive(10000, [$svHold, $cardAuth]))->toBe([
        'status' => CS::Authorized,
        'authorized' => 10000,
        'captured' => 0,
        'committed' => 10000,
    ]);

    // 4. Capture StoredValue 30.00 -> PartiallyCaptured (70.00 still open auth on card)
    $svCaptured = smSession(PaymentSessionKind::StoredValue, SS::Captured, 3000, authorized: 3000, captured: 3000, captureClosed: true);
    expect(PaymentCollectionStateMachine::derive(10000, [$svCaptured, $cardAuth]))->toBe([
        'status' => CS::PartiallyCaptured,
        'authorized' => 7000,
        'captured' => 3000,
        'committed' => 10000,
    ]);

    // 5. Multicapture Card step 1: capture 40.00 with isFinalCapture = false -> still PartiallyCaptured, 30.00 open auth remains!
    $cardPartial = smSession(PaymentSessionKind::Gateway, SS::PartiallyCaptured, 7000, authorized: 7000, captured: 4000, captureClosed: false);
    expect(PaymentCollectionStateMachine::derive(10000, [$svCaptured, $cardPartial]))->toBe([
        'status' => CS::PartiallyCaptured,
        'authorized' => 3000,
        'captured' => 7000,
        'committed' => 10000,
    ]);

    // 6. Multicapture Card step 2: capture 30.00 with isFinalCapture = true -> Captured!
    $cardFinal = smSession(PaymentSessionKind::Gateway, SS::Captured, 7000, authorized: 7000, captured: 7000, captureClosed: true);
    expect(PaymentCollectionStateMachine::derive(10000, [$svCaptured, $cardFinal]))->toBe([
        'status' => CS::Captured,
        'authorized' => 0,
        'captured' => 10000,
        'committed' => 10000,
    ]);
});

it('handles partial final capture closing remaining open auth, voided/failed sessions, overcapture, and cancellation', function (): void {
    // Partial final capture (40.00 of 70.00 authorized, captureClosed: true) closes open auth -> 0 open auth
    $partialClosed = smSession(PaymentSessionKind::Gateway, SS::Captured, 7000, authorized: 7000, captured: 4000, captureClosed: true);
    expect(PaymentCollectionStateMachine::derive(10000, [$partialClosed]))->toBe([
        'status' => CS::PartiallyCaptured,
        'authorized' => 0,
        'captured' => 4000,
        'committed' => 4000,
    ]);

    // Failed and voided sessions contribute zero committed/authorized
    $failed = smSession(PaymentSessionKind::Gateway, SS::Failed, 5000);
    $voided = smSession(PaymentSessionKind::StoredValue, SS::Voided, 3000, authorized: 3000, captureClosed: true);
    expect(PaymentCollectionStateMachine::derive(10000, [$failed, $voided], cancelled: true))->toBe([
        'status' => CS::Cancelled,
        'authorized' => 0,
        'captured' => 0,
        'committed' => 0,
    ]);

    // Overcapture (> target amount) still derives Captured
    $over = smSession(PaymentSessionKind::Gateway, SS::Captured, 10000, authorized: 10000, captured: 10500, captureClosed: true);
    expect(PaymentCollectionStateMachine::derive(10000, [$over])['status'])->toBe(CS::Captured);
});

it('enforces session operation guards for capture, incremental authorization, and void', function (): void {
    $openGateway = smSession(PaymentSessionKind::Gateway, SS::Authorized, 5000, authorized: 5000);
    $partialOpenGateway = smSession(PaymentSessionKind::Gateway, SS::PartiallyCaptured, 5000, authorized: 5000, captured: 2000, captureClosed: false);
    $closedGateway = smSession(PaymentSessionKind::Gateway, SS::Captured, 5000, authorized: 5000, captured: 5000, captureClosed: true);
    $openSv = smSession(PaymentSessionKind::StoredValue, SS::Authorized, 3000, authorized: 3000);

    expect(PaymentCollectionStateMachine::canCapture($openGateway))->toBeTrue()
        ->and(PaymentCollectionStateMachine::canCapture($partialOpenGateway))->toBeTrue()
        ->and(PaymentCollectionStateMachine::canCapture($closedGateway))->toBeFalse()
        ->and(PaymentCollectionStateMachine::canIncrementalAuthorize($openGateway))->toBeTrue()
        ->and(PaymentCollectionStateMachine::canIncrementalAuthorize($partialOpenGateway))->toBeTrue()
        ->and(PaymentCollectionStateMachine::canIncrementalAuthorize($closedGateway))->toBeFalse()
        ->and(PaymentCollectionStateMachine::canIncrementalAuthorize($openSv))->toBeFalse()
        ->and(PaymentCollectionStateMachine::canVoid($openGateway))->toBeTrue()
        ->and(PaymentCollectionStateMachine::canVoid($partialOpenGateway))->toBeFalse()
        ->and(PaymentCollectionStateMachine::canVoid($openSv))->toBeTrue();
});
