<?php

declare(strict_types=1);

use Commerz\Inventory\Panels\StockLevelsPanel;
use Commerz\Inventory\Panels\StockLocationsPanel;
use Commerz\Inventory\Plugins\InventoryPlugin;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;

it('exposes headless inventory panels through InventoryPlugin', function (): void {
    expect((new InventoryPlugin)->panelProviders())->toBe([
        StockLocationsPanel::class,
        StockLevelsPanel::class,
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

    foreach ([new StockLocationsPanel, new StockLevelsPanel] as $panel) {
        $descriptor = $panel->descriptor();

        expect($descriptor->version)->toBe('panel/1')
            ->and($descriptor->owningModule)->toBe('axiomasoft/commerz-inventory')
            ->and($descriptor->supportedActions)->not->toBeEmpty();

        $ref = new PanelReference(providerKey: $descriptor->key, panelId: '1', parent: null);

        expect($panel->access($guest, $ref)->view)->toBeFalse()
            ->and($panel->access($member, $ref)->view)->toBeTrue()
            ->and($panel->access($member, $ref)->actions)->toBe($descriptor->supportedActions);
    }
});
