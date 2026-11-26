<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Data\GatewayAuthorizeRequest;
use Commerz\Sales\Gateways\Data\GatewayCaptureRequest;
use Commerz\Sales\Gateways\Data\GatewayCaptureResult;
use Commerz\Sales\Gateways\Data\GatewayIncrementalAuthRequest;
use Commerz\Sales\Gateways\Data\GatewayLineItem;
use Commerz\Sales\Gateways\Data\GatewayVoidRequest;
use Commerz\Sales\Gateways\Data\PaymentCapabilities;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Exceptions\GatewayCapabilityUnsupported;
use Commerz\Sales\Gateways\Internal\ManualGateway;
use Commerz\Sales\Tests\Fixtures\AcquiringRecordingGateway;
use Packman\Errors\Enums\ErrorCategory;

it('defaults basic gateways to empty PaymentCapabilities and reads capabilities from CapablePaymentGateway', function (): void {
    $basic = PaymentCapabilities::of(new ManualGateway);

    expect($basic)->toEqual(PaymentCapabilities::basic())
        ->and($basic->authorizeCaptureVoid)->toBeFalse()
        ->and($basic->multicapture)->toBeFalse()
        ->and($basic->incrementalAuth)->toBeFalse()
        ->and($basic->overcapture)->toBeFalse()
        ->and($basic->lineItemsL3)->toBeFalse();

    AcquiringRecordingGateway::configure(new PaymentCapabilities(
        authorizeCaptureVoid: true,
        multicapture: true,
        incrementalAuth: true,
        overcapture: false,
        lineItemsL3: true,
    ));

    $capable = PaymentCapabilities::of(new AcquiringRecordingGateway);

    expect($capable->authorizeCaptureVoid)->toBeTrue()
        ->and($capable->multicapture)->toBeTrue()
        ->and($capable->incrementalAuth)->toBeTrue()
        ->and($capable->overcapture)->toBeFalse()
        ->and($capable->lineItemsL3)->toBeTrue();
});

it('validates GatewayLineItem, GatewayAuthorizeRequest, GatewayCaptureRequest, GatewayVoidRequest, and GatewayIncrementalAuthRequest', function (): void {
    $item = new GatewayLineItem(
        sku: 'SKU-100',
        name: 'Industrial Pump',
        quantity: 2,
        unitPrice: Money::of('50.00', 'USD'),
        total: Money::of('100.00', 'USD'),
        taxAmount: Money::of('10.00', 'USD'),
        commodityCode: '841370',
        unitOfMeasure: 'EA',
    );

    $auth = new GatewayAuthorizeRequest(
        reference: 'session:1',
        targetRef: 'order:1',
        amount: Money::of('100.00', 'USD'),
        orderNumber: 'ORD-1001',
        lineItems: [$item],
    );

    $nonFinalCapture = new GatewayCaptureRequest(
        reference: 'capture:1',
        paymentReference: 'session:1',
        paymentExternalId: 'ext-1',
        amount: Money::of('40.00', 'USD'),
        isFinalCapture: false,
        lineItems: [$item],
    );

    $finalCapture = new GatewayCaptureRequest(
        reference: 'capture:2',
        paymentReference: 'session:1',
        paymentExternalId: 'ext-1',
        amount: Money::of('60.00', 'USD'),
        isFinalCapture: true,
    );

    $captureResult = new GatewayCaptureResult(
        status: GatewayPaymentStatus::Captured,
        captured: Money::of('40.00', 'USD'),
        isFinalCapture: false,
        externalId: 'cap-1',
    );

    $void = new GatewayVoidRequest('void:1', 'session:1', 'ext-1', 'customer_cancelled');

    $incAuth = new GatewayIncrementalAuthRequest(
        reference: 'incauth:1',
        paymentReference: 'session:1',
        paymentExternalId: 'ext-1',
        additionalAmount: Money::of('20.00', 'USD'),
        newAuthorizedTotal: Money::of('120.00', 'USD'),
    );

    expect($auth->lineItems)->toHaveCount(1)
        ->and($nonFinalCapture->isFinalCapture)->toBeFalse()
        ->and($finalCapture->isFinalCapture)->toBeTrue()
        ->and($captureResult->isFinalCapture)->toBeFalse()
        ->and($void->reason)->toBe('customer_cancelled')
        ->and($incAuth->newAuthorizedTotal->amountMinor)->toBe(12000);
});

it('rejects invalid acquiring DTO fields and currency mismatches', function (): void {
    expect(fn () => new GatewayLineItem('', 'Name', 1, Money::of('1.00', 'USD'), Money::of('1.00', 'USD')))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => new GatewayLineItem('SKU', 'Name', 0, Money::of('1.00', 'USD'), Money::of('1.00', 'USD')))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => new GatewayLineItem('SKU', 'Name', 1, Money::of('1.00', 'USD'), Money::of('1.00', 'EUR')))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => new GatewayCaptureRequest('cap:1', 'session:1', null, Money::of('0.00', 'USD')))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => new GatewayIncrementalAuthRequest('inc:1', 'session:1', null, Money::of('50.00', 'USD'), Money::of('40.00', 'USD')))
        ->toThrow(InvalidCommand::class);

    $error = new GatewayCapabilityUnsupported('manual', 'multicapture');
    expect($error->code())->toBe('sales.gateway_capability_unsupported')
        ->and($error->category())->toBe(ErrorCategory::Validation)
        ->and($error->context())->toBe(['gateway' => 'manual', 'capability' => 'multicapture']);
});
