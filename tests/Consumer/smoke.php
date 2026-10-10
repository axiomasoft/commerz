<?php

declare(strict_types=1);

// Boots a fresh Laravel 13 application that installed Commerz from composer archives only (no monorepo).
// CONSUMER_PROFILE=full (all packages) | crm (core + sales, commerz.stock=unlimited).

use Commerz\Catalog\Catalog;
use Commerz\Catalog\CatalogServiceProvider;
use Commerz\Commerz;
use Commerz\CommerzServiceProvider;
use Commerz\Inventory\Inventory;
use Commerz\Inventory\InventoryServiceProvider;
use Commerz\Pricing\Pricing;
use Commerz\Pricing\PricingServiceProvider;
use Commerz\Promotions\Promotions;
use Commerz\Promotions\PromotionsServiceProvider;
use Commerz\Sales\Sales;
use Commerz\Sales\SalesServiceProvider;
use Commerz\Shipping\Shipping;
use Commerz\Shipping\ShippingServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$base = __DIR__;

require $base.'/vendor/autoload.php';

$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$profile = getenv('CONSUMER_PROFILE') ?: 'full';

$profiles = [
    'full' => [
        'providers' => [
            'axiomasoft/commerz' => CommerzServiceProvider::class,
            'axiomasoft/commerz-catalog' => CatalogServiceProvider::class,
            'axiomasoft/commerz-inventory' => InventoryServiceProvider::class,
            'axiomasoft/commerz-pricing' => PricingServiceProvider::class,
            'axiomasoft/commerz-promotions' => PromotionsServiceProvider::class,
            'axiomasoft/commerz-shipping' => ShippingServiceProvider::class,
            'axiomasoft/commerz-sales' => SalesServiceProvider::class,
        ],
        'roots' => [Catalog::class, Inventory::class, Pricing::class, Promotions::class, Shipping::class, Sales::class],
        'plugins' => ['commerz.catalog', 'commerz.core', 'commerz.inventory', 'commerz.pricing', 'commerz.promotions', 'commerz.sales', 'commerz.shipping'],
    ],
    'crm' => [
        'providers' => [
            'axiomasoft/commerz' => CommerzServiceProvider::class,
            'axiomasoft/commerz-sales' => SalesServiceProvider::class,
        ],
        'roots' => [Sales::class],
        'plugins' => ['commerz.core', 'commerz.sales'],
    ],
];

$fail = static function (string $message): never {
    fwrite(STDERR, "smoke: FAIL {$message}\n");

    exit(1);
};

if (! isset($profiles[$profile])) {
    $fail("unknown profile {$profile}");
}

$expected = $profiles[$profile];

foreach ($expected['providers'] as $package => $provider) {
    if (! $app->providerIsLoaded($provider)) {
        $fail("{$provider} is not registered (package discovery)");
    }

    $file = (new ReflectionClass($provider))->getFileName();
    $packageDir = $base.'/vendor/'.$package;

    if (is_link($packageDir)) {
        $fail("{$packageDir} is a symlink (monorepo path install)");
    }

    $real = realpath((string) $file);

    if ($real === false || ! str_starts_with($real, realpath($packageDir).DIRECTORY_SEPARATOR)) {
        $fail("{$provider} is not loaded from {$packageDir}: {$file}");
    }
}

// commerz:doctor only fingerprints the core schema; every package's own migrations must be in the archive and ran.
$ran = array_flip(DB::table('migrations')->pluck('migration')->all());

foreach (array_keys($expected['providers']) as $package) {
    $migrations = glob($base.'/vendor/'.$package.'/database/migrations/*.php') ?: [];

    if ($migrations === []) {
        $fail("{$package} ships no migrations");
    }

    foreach ($migrations as $migration) {
        if (! isset($ran[basename($migration, '.php')])) {
            $fail('migration not applied: '.basename($migration));
        }
    }
}

$plugins = array_map(static fn ($plugin) => $plugin->id, $app->make(Commerz::class)->plugins()->describe());
sort($plugins);

if ($plugins !== $expected['plugins']) {
    $fail('plugins '.json_encode($plugins).' != '.json_encode($expected['plugins']));
}

foreach ($expected['roots'] as $root) {
    if (! $app->make($root) instanceof $root) {
        $fail("{$root} does not resolve");
    }
}

if ($profile === 'crm' && config('commerz.stock') !== 'unlimited') {
    $fail('commerz.stock is not unlimited');
}

echo 'smoke: ok profile='.$profile.' providers='.count($expected['providers']).' plugins='.count($plugins)."\n";
