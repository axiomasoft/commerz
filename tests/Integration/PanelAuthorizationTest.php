<?php

declare(strict_types=1);

use Commerz\Catalog\Plugins\CatalogPlugin;
use Commerz\Inventory\Plugins\InventoryPlugin;
use Commerz\Panels\Contracts\PanelPermissions;
use Commerz\Plugins\CorePlugin;
use Commerz\Pricing\Plugins\PricingPlugin;
use Commerz\Promotions\Plugins\PromotionsPlugin;
use Commerz\Sales\Plugins\SalesPlugin;
use Commerz\Shipping\Plugins\ShippingPlugin;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;

$panels = [];
foreach ([
    CorePlugin::class,
    CatalogPlugin::class,
    InventoryPlugin::class,
    PricingPlugin::class,
    PromotionsPlugin::class,
    ShippingPlugin::class,
    SalesPlugin::class,
] as $plugin) {
    foreach ((new $plugin)->panelProviders() as $provider) {
        $panels[$provider] = [$provider];
    }
}

dataset('headless commerce panels', $panels);

function commercePanel(string $provider, ?ContainerContract $container = null): PanelProvider
{
    if (! is_a($provider, PanelProvider::class, true)) {
        throw new RuntimeException($provider.' is not a panel provider.');
    }

    return (new ReflectionClass($provider))->newInstance($container);
}

function commercePanelContext(string $kind = 'user'): PanelContext
{
    $tenant = new readonly class implements ContextScopeInterface
    {
        public function id(): string
        {
            return 'shop-1';
        }
    };

    return new PanelContext($tenant, $kind, $kind === 'user' ? 'actor-1' : null);
}

it('denies panel access without a container or usable permissions', function (string $provider): void {
    foreach ([null, new Container, new Container, new Container] as $i => $container) {
        if ($i === 2) {
            $container->bind(PanelPermissions::class, static fn () => throw new RuntimeException('unavailable'));
        } elseif ($i === 3) {
            $container->instance(PanelPermissions::class, new stdClass);
        }

        $panel = commercePanel($provider, $container);
        $reference = new PanelReference($panel->descriptor()->key, 'resource-1', null);
        foreach (['guest', 'user', 'system'] as $kind) {
            $access = $panel->access(commercePanelContext($kind), $reference);
            expect($access->view)->toBeFalse()->and($access->actions)->toBe([]);
        }
    }
})->with('headless commerce panels');

it('requires resource and tenant permissions for view and each action', function (string $provider): void {
    $container = new Container;
    $permissions = new class implements PanelPermissions
    {
        public bool $denyView = false;

        public bool $throwOnAction = false;

        public function allows(PanelContext $context, PanelReference $panel, string $action): bool
        {
            if ($this->throwOnAction && $action !== 'view') {
                throw new RuntimeException('policy unavailable');
            }

            return ! $this->denyView && $context->actorId === 'actor-1'
                && $context->tenant->id() === 'shop-1' && $panel->panelId === 'resource-1'
                && in_array($action, ['view', 'update'], true);
        }
    };
    $container->instance(PanelPermissions::class, $permissions);
    $panel = commercePanel($provider, $container);
    $descriptor = $panel->descriptor();
    $reference = new PanelReference($descriptor->key, 'resource-1', null);
    $context = commercePanelContext();
    $access = $panel->access($context, $reference);

    expect($access->view)->toBeTrue()
        ->and($access->actions)->toBe(array_values(array_intersect($descriptor->supportedActions, ['view', 'update'])))
        ->and($panel->access(commercePanelContext('guest'), $reference)->view)->toBeFalse()
        ->and($panel->access(commercePanelContext('system'), $reference)->view)->toBeFalse()
        ->and($panel->access($context, new PanelReference('foreign.panel', 'resource-1', null))->view)->toBeFalse()
        ->and($panel->access($context, new PanelReference($descriptor->key, 'resource-2', null))->view)->toBeFalse();

    $permissions->denyView = true;
    expect($panel->access($context, $reference)->view)->toBeFalse()
        ->and($panel->access($context, $reference)->actions)->toBe([]);

    $permissions->denyView = false;
    $permissions->throwOnAction = true;
    expect($panel->access($context, $reference)->view)->toBeFalse()
        ->and($panel->access($context, $reference)->actions)->toBe([]);
})->with('headless commerce panels');
