<?php

declare(strict_types=1);

use Commerz\Sales\Common\Internal\SalesConfig;
use Commerz\Sales\SalesServiceProvider;

it('registers the sales service provider and config', function (): void {
    expect(app()->getProviders(SalesServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-sales'))->toBeArray()
        ->and(config('commerz-sales.checkout.number_generator'))->toBe('sequence');
})->group('boot');

it('resolves the typed config as a singleton from the config repository', function (): void {
    config(['commerz-sales.carts.max_lines' => 7]);
    app()->forgetInstance(SalesConfig::class);

    expect(app(SalesConfig::class)->maxCartLines)->toBe(7)
        ->and(app(SalesConfig::class))->toBe(app(SalesConfig::class));
})->group('boot');

it('loads the sales migrations', function (): void {
    $paths = array_map(basename(...), glob(__DIR__.'/../../database/migrations/*.php') ?: []);

    expect($paths)->toBe([
        '2026_10_14_100001_create_commerz_cart_tables.php',
        '2026_10_14_100002_create_commerz_order_tables.php',
        '2026_10_14_100003_create_commerz_fulfillment_tables.php',
        '2026_10_14_100004_create_commerz_payment_tables.php',
        '2026_10_14_100005_add_commerz_fulfillment_booking_intent.php',
    ]);
})->group('boot');
