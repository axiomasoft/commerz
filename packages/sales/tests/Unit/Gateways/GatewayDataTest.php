<?php

declare(strict_types=1);

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Data\GatewayPaymentRequest;
use Commerz\Sales\Gateways\Data\GatewayPaymentResult;
use Commerz\Sales\Gateways\Data\GatewayRefundRequest;
use Commerz\Sales\Gateways\Data\GatewayRefundResult;
use Commerz\Sales\Gateways\Enums\GatewayPaymentStatus;
use Commerz\Sales\Gateways\Enums\GatewayRefundStatus;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Gateways\Exceptions\PaymentDeclined;
use Commerz\Sales\Numbering\Exceptions\InvalidOrderNumber;

function gwRub(int $minor): Money
{
    return Money::ofMinor($minor, 'RUB');
}

function gwRequest(array $overrides = []): GatewayPaymentRequest
{
    return new GatewayPaymentRequest(...[
        'reference' => 'payment:1', 'orderRef' => 'order:1', 'orderNumber' => 'ORD-1001', 'amount' => gwRub(1000), ...$overrides,
    ]);
}

it('carries a payment request', function (): void {
    $request = gwRequest(['customer' => CustomerRef::of('user', '7'), 'customerEmail' => 'a@b.test', 'meta' => ['return_url' => 'https://shop.test/ok', 'retries' => 2, 'save' => true]]);

    expect($request->reference)->toBe('payment:1')
        ->and($request->customer?->id)->toBe('7')
        ->and($request->meta)->toBe(['return_url' => 'https://shop.test/ok', 'retries' => 2, 'save' => true]);
});

it('refuses a malformed payment request', function (array $overrides, string $field): void {
    expect(fn () => gwRequest($overrides))
        ->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('sales.invalid_gateway_payment')->and($e->field)->toBe($field));
})->with([
    'empty reference' => [['reference' => ''], 'reference'],
    'long reference' => [['reference' => str_repeat('a', 192)], 'reference'],
    'empty order ref' => [['orderRef' => ''], 'orderRef'],
    'empty order number' => [['orderNumber' => ''], 'orderNumber'],
    'zero amount' => [['amount' => gwRub(0)], 'amount'],
    'negative amount' => [['amount' => gwRub(-1)], 'amount'],
    'empty email' => [['customerEmail' => ''], 'customerEmail'],
    'long email' => [['customerEmail' => str_repeat('a', 192)], 'customerEmail'],
    'list meta' => [['meta' => ['x']], 'meta'],
    'nested meta' => [['meta' => ['a' => ['b']]], 'meta'],
    'null meta value' => [['meta' => ['a' => null]], 'meta'],
]);

it('takes a result of any status and checks only its shape', function (): void {
    foreach (GatewayPaymentStatus::cases() as $status) {
        expect(new GatewayPaymentResult($status)->status)->toBe($status);
    }

    $result = new GatewayPaymentResult(GatewayPaymentStatus::Captured, 'ext-1', gwRub(1000), gwRub(1000), meta: ['k' => 'v']);

    expect($result->externalId)->toBe('ext-1')->and($result->captured?->amountMinor)->toBe(1000)->and($result->actionUrl)->toBeNull();
});

it('refuses a malformed payment result', function (Closure $make, string $field): void {
    expect($make)->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('sales.invalid_gateway_payment')->and($e->field)->toBe($field));
})->with([
    'empty external id' => [fn () => new GatewayPaymentResult(GatewayPaymentStatus::Pending, ''), 'externalId'],
    'external id longer than its column' => [fn () => new GatewayPaymentResult(GatewayPaymentStatus::Pending, str_repeat('x', 192)), 'externalId'],
    'negative authorized' => [fn () => new GatewayPaymentResult(GatewayPaymentStatus::Authorized, authorized: gwRub(-1)), 'authorized'],
    'negative captured' => [fn () => new GatewayPaymentResult(GatewayPaymentStatus::Captured, captured: gwRub(-1)), 'captured'],
    'empty action url' => [fn () => new GatewayPaymentResult(GatewayPaymentStatus::RequiresAction, actionUrl: ''), 'actionUrl'],
    'object meta value' => [fn () => new GatewayPaymentResult(GatewayPaymentStatus::Pending, meta: ['a' => new stdClass]), 'meta'],
]);

it('carries and checks a refund request', function (): void {
    $request = new GatewayRefundRequest('refund:1', 'payment:1', 'ext-1', gwRub(500), 'damaged');

    expect($request->paymentExternalId)->toBe('ext-1')->and($request->reason)->toBe('damaged')
        ->and(new GatewayRefundRequest('refund:1', 'payment:1', null, gwRub(500))->reason)->toBeNull();

    foreach ([
        'reference' => fn () => new GatewayRefundRequest('', 'payment:1', null, gwRub(1)),
        'paymentReference' => fn () => new GatewayRefundRequest('refund:1', '', null, gwRub(1)),
        'paymentExternalId' => fn () => new GatewayRefundRequest('refund:1', 'payment:1', '', gwRub(1)),
        'amount' => fn () => new GatewayRefundRequest('refund:1', 'payment:1', null, gwRub(0)),
        'reason' => fn () => new GatewayRefundRequest('refund:1', 'payment:1', null, gwRub(1), str_repeat('a', 192)),
    ] as $field => $make) {
        expect($make)->toThrow(fn (InvalidCommand $e) => expect($e->code())->toBe('sales.invalid_gateway_refund')->and($e->field)->toBe($field));
    }
});

it('carries and checks a refund result', function (): void {
    expect(new GatewayRefundResult(GatewayRefundStatus::Pending, 'r-1', ['k' => 1])->meta)->toBe(['k' => 1])
        ->and(fn () => new GatewayRefundResult(GatewayRefundStatus::Succeeded, ''))->toThrow(InvalidCommand::class)
        ->and(fn () => new GatewayRefundResult(GatewayRefundStatus::Succeeded, str_repeat('x', 192)))->toThrow(InvalidCommand::class, 'externalId')
        ->and(fn () => new GatewayRefundResult(GatewayRefundStatus::Succeeded, meta: [1 => 'x']))->toThrow(InvalidCommand::class);
});

it('classifies the gateway and numbering errors', function (): void {
    $declined = new PaymentDeclined('acme.pay', 'card_expired');
    $invalid = new InvalidGatewayResult('acme.pay', 'captured exceeds amount');
    $number = new InvalidOrderNumber('sequence', '-1');

    expect($declined)->toBeInstanceOf(CommerzException::class)
        ->and([$declined->code(), $declined->category(), $declined->context()])->toBe(['sales.payment_declined', ErrorCategory::Conflict, ['gateway' => 'acme.pay', 'reason' => 'card_expired']])
        ->and([$invalid->code(), $invalid->category(), $invalid->context()])->toBe(['sales.invalid_gateway_result', ErrorCategory::Integration, ['gateway' => 'acme.pay', 'reason' => 'captured exceeds amount']])
        ->and([$number->code(), $number->category(), $number->context()])->toBe(['sales.invalid_order_number', ErrorCategory::Configuration, ['generator' => 'sequence', 'number' => '-1']]);
});
