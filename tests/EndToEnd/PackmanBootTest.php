<?php

declare(strict_types=1);

use Commerz\Plugins\CorePlugin;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Doctor\Contracts\DoctorCheck;
use Packman\Doctor\Laravel\DoctorChecks;
use Packman\Panels\Contracts\Registry\PanelCatalogue;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Subjects\EntityReference;
use Packman\Panels\Laravel\PanelsServiceProvider;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use Packman\Plugins\Contracts\Contributions\Contributions;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginRegistrar;
use Packman\Plugins\Testing\PluginGraph;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;

interface CommerceBootCapability
{
    public function value(): string;
}

final class CommerceBootProvider implements CommerceBootCapability
{
    public function value(): string
    {
        return 'active';
    }
}

final class CommerceBootPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest(id: 'host.boot', version: '1.0.0', requires: [CorePlugin::ID]);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->defineCapability(CommerceBootCapability::class)->provides(CommerceBootCapability::class, CommerceBootProvider::class);
    }
}

it('boots one Packman graph with every commerce facet in either provider order', function (bool $reverse): void {
    if ($reverse) {
        $this->bootInReverseOrder();
    }

    ExtensionRegistrar::builtIn(CommerceBootPlugin::class);
    $this->app->register(PanelsServiceProvider::class);
    $inspector = $this->app->make(PluginInspector::class);
    $plugins = $inspector->describe();

    expect($inspector)->toBe($this->app->make(Contributions::class))
        ->and(array_column($plugins, 'id'))->toBe([
            'commerz.core', 'commerz.catalog', 'commerz.inventory', 'commerz.pricing',
            'commerz.promotions', 'commerz.sales', 'commerz.shipping', 'host.boot',
        ])
        ->and($this->app->make(Capabilities::class)->require(CommerceBootCapability::class)->value())->toBe('active');

    $standalone = PluginGraph::compile($this->app, [], builtIn: array_column($plugins, 'class'));
    expect(array_column($standalone->describe(), 'id'))->toBe(array_column($plugins, 'id'));

    $panelClasses = [];
    $schemaClasses = array_map(static fn (SchemaSetProvider $provider): string => $provider::class, iterator_to_array($this->app->tagged(SchemaSetProvider::class)));
    $checkClasses = array_map(static fn (DoctorCheck $check): string => $check::class, iterator_to_array($this->app->tagged(DoctorChecks::TAG)));

    foreach (array_slice($plugins, 0, 7) as $plugin) {
        $facets = $inspector->facetsOf($plugin->id);
        expect($facets)->not->toBeNull()
            ->and($plugin->kinds)->not->toBeEmpty()
            ->and($facets->panelProviders)->not->toBeEmpty()
            ->and($facets->healthChecks)->not->toBeEmpty()
            ->and($facets->schemaSetProviders)->not->toBeEmpty();
        foreach ($facets->schemaSetProviders as $provider) {
            expect($schemaClasses)->toContain($provider);
        }
        foreach ($facets->healthChecks as $check) {
            expect($checkClasses)->toContain($check);
        }
        $panelClasses = [...$panelClasses, ...$facets->panelProviders];
    }

    expect($panelClasses)->toHaveCount(20);
    $catalogue = $this->app->make(PanelCatalogue::class);
    $tenant = new readonly class implements ContextScopeInterface
    {
        public function id(): string
        {
            return 'shop-1';
        }
    };
    $context = new PanelContext($tenant, 'user', 'actor-1');
    $descriptors = $catalogue->forEntity($context, new EntityReference('store', 'store-1'));
    expect(array_column($descriptors, 'key'))->toEqualCanonicalizing(array_map(static fn (string $provider): string => (new $provider)->descriptor()->key, $panelClasses));
})->with(['forward' => false, 'reverse' => true]);
