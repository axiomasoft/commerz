<?php

declare(strict_types=1);

use Commerz\Shipping\Panels\CarriersPanel;
use Commerz\Shipping\Panels\ShippingMethodsPanel;
use Commerz\Shipping\Panels\ShippingZonesPanel;
use Commerz\Shipping\Plugins\ShippingPlugin;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;

it('exposes headless shipping panels through ShippingPlugin', function (): void {
    expect((new ShippingPlugin)->panelProviders())->toBe([
        ShippingZonesPanel::class,
        ShippingMethodsPanel::class,
        CarriersPanel::class,
    ]);

    $tenant = new readonly class implements ContextScopeInterface
    {
        public function id(): string
        {
            return 'default';
        }
    };

    $guest = new PanelContext(tenant: $tenant, actorKind: 'guest', actorId: null);
    $member = new PanelContext(tenant: $tenant, actorKind: 'user', actorId: '42');

    foreach ([new ShippingZonesPanel, new ShippingMethodsPanel, new CarriersPanel] as $panel) {
        $descriptor = $panel->descriptor();

        expect($descriptor->version)->toBe('panel/1')
            ->and($descriptor->owningModule)->toBe('axiomasoft/commerz-shipping')
            ->and($descriptor->supportedActions)->not->toBeEmpty();

        $ref = new PanelReference(providerKey: $descriptor->key, panelId: '1', parent: null);

        expect($panel->access($guest, $ref)->view)->toBeFalse()
            ->and($panel->access($member, $ref)->view)->toBeFalse()
            ->and($panel->access($member, $ref)->actions)->toBe([]);
    }
});
