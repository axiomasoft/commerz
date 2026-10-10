<?php

declare(strict_types=1);

use Commerz\Errors\InvalidConfiguration;
use Commerz\Sales\Common\Internal\SalesConfig;

it('defaults to the shipped values', function (): void {
    $config = SalesConfig::fromArray([]);

    expect($config->maxCartLines)->toBe(250)
        ->and($config->maxLineQuantity)->toBe(100_000)
        ->and($config->requireDelivery)->toBeTrue()
        ->and($config->numberGenerator)->toBe('sequence')
        ->and($config->paymentDueMinutes)->toBe(4320)
        ->and($config->expireBatch)->toBe(100)
        ->and($config->leaseSeconds)->toBe(120);
});

it('reads the shipped config file', function (): void {
    expect(SalesConfig::fromArray(require __DIR__.'/../../config/commerz-sales.php'))->toEqual(SalesConfig::fromArray([]));
});

it('reads every key', function (): void {
    $config = SalesConfig::fromArray([
        'carts' => ['max_lines' => 10, 'max_quantity' => 5],
        'checkout' => ['require_delivery' => false, 'number_generator' => 'acme.numbers'],
        'orders' => ['payment_due_minutes' => 60, 'expire_batch' => 7],
        'payments' => ['lease_seconds' => 30],
    ]);

    expect($config->maxCartLines)->toBe(10)
        ->and($config->maxLineQuantity)->toBe(5)
        ->and($config->requireDelivery)->toBeFalse()
        ->and($config->numberGenerator)->toBe('acme.numbers')
        ->and($config->paymentDueMinutes)->toBe(60)
        ->and($config->expireBatch)->toBe(7)
        ->and($config->leaseSeconds)->toBe(30);
});

it('lets the payment deadline be switched off with null', function (): void {
    expect(SalesConfig::fromArray(['orders' => ['payment_due_minutes' => null]])->paymentDueMinutes)->toBeNull();
});

it('names the key of a wrong value', function (array $config, string $key): void {
    expect(fn () => SalesConfig::fromArray($config))
        ->toThrow(fn (InvalidConfiguration $e) => expect($e->getMessage())->toContain($key));
})->with([
    'carts not an array' => [['carts' => 1], 'commerz-sales.carts'],
    'no lines allowed' => [['carts' => ['max_lines' => 0]], 'commerz-sales.carts.max_lines'],
    'too many lines' => [['carts' => ['max_lines' => 10_001]], 'commerz-sales.carts.max_lines'],
    'lines not an int' => [['carts' => ['max_lines' => '5']], 'commerz-sales.carts.max_lines'],
    'quantity zero' => [['carts' => ['max_quantity' => 0]], 'commerz-sales.carts.max_quantity'],
    'quantity too large' => [['carts' => ['max_quantity' => 1_000_001]], 'commerz-sales.carts.max_quantity'],
    'require_delivery not a bool' => [['checkout' => ['require_delivery' => 'yes']], 'commerz-sales.checkout.require_delivery'],
    'generator not a string' => [['checkout' => ['number_generator' => 1]], 'commerz-sales.checkout.number_generator'],
    'generator not a key' => [['checkout' => ['number_generator' => 'Sequence']], 'commerz-sales.checkout.number_generator'],
    'generator empty' => [['checkout' => ['number_generator' => '']], 'commerz-sales.checkout.number_generator'],
    'due zero' => [['orders' => ['payment_due_minutes' => 0]], 'commerz-sales.orders.payment_due_minutes'],
    'due too long' => [['orders' => ['payment_due_minutes' => 525_601]], 'commerz-sales.orders.payment_due_minutes'],
    'due not an int' => [['orders' => ['payment_due_minutes' => '60']], 'commerz-sales.orders.payment_due_minutes'],
    'batch zero' => [['orders' => ['expire_batch' => 0]], 'commerz-sales.orders.expire_batch'],
    'batch too large' => [['orders' => ['expire_batch' => 1001]], 'commerz-sales.orders.expire_batch'],
    'lease too short' => [['payments' => ['lease_seconds' => 9]], 'commerz-sales.payments.lease_seconds'],
    'lease too long' => [['payments' => ['lease_seconds' => 3601]], 'commerz-sales.payments.lease_seconds'],
]);

it('accepts the bounds', function (): void {
    $low = SalesConfig::fromArray([
        'carts' => ['max_lines' => 1, 'max_quantity' => 1],
        'orders' => ['payment_due_minutes' => 1, 'expire_batch' => 1],
        'payments' => ['lease_seconds' => 10],
    ]);
    $high = SalesConfig::fromArray([
        'carts' => ['max_lines' => 10_000, 'max_quantity' => 1_000_000],
        'orders' => ['payment_due_minutes' => 525_600, 'expire_batch' => 1000],
        'payments' => ['lease_seconds' => 3600],
    ]);

    expect([$low->maxCartLines, $low->maxLineQuantity, $low->paymentDueMinutes, $low->expireBatch, $low->leaseSeconds])->toBe([1, 1, 1, 1, 10])
        ->and([$high->maxCartLines, $high->maxLineQuantity, $high->paymentDueMinutes, $high->expireBatch, $high->leaseSeconds])->toBe([10_000, 1_000_000, 525_600, 1000, 3600]);
});
