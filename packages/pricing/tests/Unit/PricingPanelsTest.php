<?php

declare(strict_types=1);

use Commerz\Pricing\Panels\PriceListsPanel;
use Commerz\Pricing\Panels\TaxRatesPanel;
use Commerz\Pricing\Panels\TaxZonesPanel;
use Commerz\Pricing\Plugins\PricingPlugin;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;

it('exposes headless pricing panels through PricingPlugin', function (): void {
    expect((new PricingPlugin)->panelProviders())->toBe([
        PriceListsPanel::class,
        TaxZonesPanel::class,
        TaxRatesPanel::class,
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

    foreach ([new PriceListsPanel, new TaxZonesPanel, new TaxRatesPanel] as $panel) {
        $descriptor = $panel->descriptor();

        expect($descriptor->version)->toBe('panel/1')
            ->and($descriptor->owningModule)->toBe('axiomasoft/commerz-pricing')
            ->and($descriptor->supportedActions)->not->toBeEmpty();

        $ref = new PanelReference(providerKey: $descriptor->key, panelId: '1', parent: null);

        expect($panel->access($guest, $ref)->view)->toBeFalse()
            ->and($panel->access($member, $ref)->view)->toBeFalse()
            ->and($panel->access($member, $ref)->actions)->toBe([]);
    }
});
