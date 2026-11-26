<?php

declare(strict_types=1);

use Commerz\Sales\Configuration\SalesOptions;
use Commerz\Sales\SalesServiceProvider;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Contracts\DoctorRegistryContract;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Schema\Contracts\Migration\SchemaSets;

it('registers the sales service provider, options, schema sets, problems, and doctor check', function (): void {
    expect(app()->getProviders(SalesServiceProvider::class))->not->toBeEmpty()
        ->and(config('commerz-sales'))->toBeArray()
        ->and(config('commerz-sales.checkout.number_generator'))->toBe('sequence')
        ->and(app(SalesOptions::class))->toBeInstanceOf(SalesOptions::class)
        ->and(app(SchemaSets::class)->all())->not->toBeEmpty()
        ->and(array_map(static fn (DoctorCheck $c): string => $c->key(), app(DoctorRegistryContract::class)->all()))->toContain('commerz.sales.configuration')
        ->and(app(ProblemRegistry::class)->fromWire('sales.order_not_found')->title)->toBe('Order Not Found');
})->group('boot');

it('resolves the typed config as a singleton from the config repository', function (): void {
    config(['commerz-sales.carts.max_lines' => 7]);
    app()->forgetInstance(SalesOptions::class);

    expect(app(SalesOptions::class)->maxCartLines)->toBe(7)
        ->and(app(SalesOptions::class))->toBe(app(SalesOptions::class));
})->group('boot');

it('loads the sales migrations', function (): void {
    $paths = array_map(basename(...), glob(__DIR__.'/../../database/migrations/*.php') ?: []);

    expect($paths)->toBe([
        '2026_10_14_100001_create_commerz_cart_tables.php',
        '2026_10_14_100002_create_commerz_order_tables.php',
        '2026_10_14_100003_create_commerz_fulfillment_tables.php',
        '2026_10_14_100004_create_commerz_payment_tables.php',
        '2026_10_14_100005_add_commerz_fulfillment_booking_intent.php',
        '2026_10_14_100006_add_commerz_seller_ref_to_lines.php',
        '2026_11_26_500003_create_exchanges_and_rma_disposition.php',
    ]);
})->group('boot');
