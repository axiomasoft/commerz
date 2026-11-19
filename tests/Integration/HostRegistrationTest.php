<?php

declare(strict_types=1);

use Commerz\CommerzServiceProvider;
use Commerz\Configuration\CommerzOptions;
use Commerz\EndToEnd\Support\EndToEndTestCase;
use Commerz\Inventory\Allocation\Contracts\AllocationStrategy;
use Commerz\Inventory\Configuration\InventoryOptions;
use Commerz\Inventory\InventoryServiceProvider;
use Commerz\Inventory\Stock\Contracts\StockLevels;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Illuminate\Config\Repository;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\MigrationServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\PluginActivationGate;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Schema\Contracts\Migration\SchemaSets;

it('activates inventory runtime, schema and commands only in its selecting host', function (string $disabledRoot): void {
    $previous = Container::getInstance();
    $boot = static function (bool $disabled) use ($disabledRoot): Application {
        $app = new Application(dirname(__DIR__, 2));
        $config = new Repository;
        $config->set('packman.schema.auto_load_migrations', false);
        if ($disabledRoot === 'gate' && $disabled) {
            $app->instance(PluginActivationGate::class, new class implements PluginActivationGate
            {
                public function isEnabled(string $pluginId): bool
                {
                    return $pluginId !== 'commerz.inventory';
                }
            });
        } elseif ($disabledRoot === 'typed') {
            $app->singleton(CommerzOptions::class, static fn (): CommerzOptions => new CommerzOptions(disabledPlugins: $disabled ? ['commerz.inventory'] : []));
        } else {
            $config->set($disabledRoot.'.disabled_plugins', $disabled ? ['commerz.inventory'] : []);
        }
        if ($disabled) {
            $config->set('commerz-inventory.expiry_batch', 'invalid when active');
        }
        $app->instance('config', $config);
        $app->register(FilesystemServiceProvider::class);
        $app->register(DatabaseServiceProvider::class);
        $app->register(MigrationServiceProvider::class);
        $app->register(CommerzServiceProvider::class);
        $app->register(InventoryServiceProvider::class);
        $app->boot();

        return $app;
    };

    try {
        $a = $boot(false);
        $b = $boot(true);
        foreach ([[$a, true], [$b, false], [$a, true]] as [$app, $enabled]) {
            $contributions = $app->make(Contributions::class);
            $sets = array_column($app->make(SchemaSets::class)->all(), 'name');
            $app->register(InventoryServiceProvider::class, true);
            $artisan = new Artisan($app, $app->make(Dispatcher::class), $app->version());
            $artisan->setContainerCommandLoader();

            expect($contributions->has(AllocationStrategy::KIND, 'priority'))->toBe($enabled)
                ->and(in_array('commerz-inventory', $sets, true))->toBe($enabled)
                ->and($app->bound(InventoryOptions::class))->toBe($enabled)
                ->and($app->bound(StockLevels::class))->toBe($enabled)
                ->and($app->make('migrator')->paths())->toBeEmpty()
                ->and($artisan->has('commerz:inventory:reconcile'))->toBe($enabled)
                ->and($app->make('db')->getConnections())->toBeEmpty();
        }
    } finally {
        Container::setInstance($previous);
    }
})->with(['packman', 'commerz', 'typed', 'gate']);

it('prepares commerce contributions when a sibling compiles the graph at the start of Laravel boot', function (bool $disabled): void {
    $previous = Container::getInstance();
    try {
        $app = new Application(dirname(__DIR__, 2));
        $config = new Repository;
        $all = ['commerz.core', 'commerz.catalog', 'commerz.inventory', 'commerz.pricing', 'commerz.promotions', 'commerz.shipping', 'commerz.sales'];
        if ($disabled) {
            $config->set('commerz.unrecognised_key', 'must not compile');
            $config->set('commerz-inventory.expiry_batch', 'invalid when active');
            $config->set('packman.disabled_plugins', $all);
        }
        $app->instance('config', $config);
        $app->register(DatabaseServiceProvider::class);
        $app->booting(static fn () => $app->make(Contributions::class));
        foreach (EndToEndTestCase::allProviders() as $provider) {
            $app->register($provider);
        }

        $app->boot();
        $plugins = $app->make(PluginInspector::class)->describe();
        $app->register(CommerzServiceProvider::class, true);
        expect(array_column($plugins, 'id'))->toEqualCanonicalizing($disabled ? [] : $all)
            ->and($app->make(Contributions::class)->has(SellableProvider::KIND, 'variant'))->toBe(! $disabled)
            ->and($app->make('db')->getConnections())->toBeEmpty();
    } finally {
        Container::setInstance($previous);
    }
})->with(['all enabled' => false, 'all disabled' => true]);
