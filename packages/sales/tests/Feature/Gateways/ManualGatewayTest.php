<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Sales\Gateways\Data\GatewayPaymentRequest;
use Commerz\Sales\Gateways\Data\GatewayRefundRequest;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Enums\GatewayRefundStatus;
use Commerz\Sales\Gateways\Internal\ManualGateway;

function manualRequest(): GatewayPaymentRequest
{
    return new GatewayPaymentRequest('payment:1', 'order:1', 'ORD-1001', Money::of('10.00', 'RUB'), meta: ['return_url' => 'https://shop.test/ok']);
}

it('is the `manual` contribution', function (): void {
    expect(new ManualGateway()->key())->toBe('manual');
});

it('starts a payment as pending, without an external id or an action', function (): void {
    $result = new ManualGateway()->initiate(manualRequest());

    expect($result->status)->toBe(GatewayPaymentStatus::Pending)
        ->and($result->externalId)->toBeNull()
        ->and($result->authorized)->toBeNull()
        ->and($result->captured)->toBeNull()
        ->and($result->actionUrl)->toBeNull()
        ->and($result->meta)->toBe(['instructions' => 'offline']);
});

it('is idempotent: the same request gives the same result', function (): void {
    $gateway = new ManualGateway;

    expect($gateway->initiate(manualRequest()))->toEqual($gateway->initiate(manualRequest()));
});

it('reports any reference as pending when asked to reconcile, since the state lives only with the shop', function (): void {
    $gateway = new ManualGateway;

    expect($gateway->find('payment:1')->status)->toBe(GatewayPaymentStatus::Pending)
        ->and($gateway->find('payment:unknown')->status)->toBe(GatewayPaymentStatus::Pending);
});

it('succeeds a refund at once, as the operator books it by hand', function (): void {
    $gateway = new ManualGateway;
    $request = new GatewayRefundRequest('refund:1', 'payment:1', null, Money::of('5.00', 'RUB'), 'damaged');

    expect($gateway->refund($request)->status)->toBe(GatewayRefundStatus::Succeeded)
        ->and($gateway->refund($request)->externalId)->toBeNull()
        ->and($gateway->findRefund('refund:1')->status)->toBe(GatewayRefundStatus::Succeeded);
});
