<?php

declare(strict_types=1);

use Commerz\Catalog\Panels\AttributesPanel;
use Commerz\Catalog\Panels\CategoriesPanel;
use Commerz\Catalog\Panels\ProductsPanel;
use Commerz\Catalog\Panels\VariantsPanel;
use Commerz\Catalog\Plugins\CatalogPlugin;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;

it('exposes headless catalog panels through CatalogPlugin', function (): void {
    expect((new CatalogPlugin)->panelProviders())->toBe([
        ProductsPanel::class,
        VariantsPanel::class,
        CategoriesPanel::class,
        AttributesPanel::class,
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

    foreach ([new ProductsPanel, new VariantsPanel, new CategoriesPanel, new AttributesPanel] as $panel) {
        $descriptor = $panel->descriptor();

        expect($descriptor->version)->toBe('panel/1')
            ->and($descriptor->owningModule)->toBe('axiomasoft/commerz-catalog')
            ->and($descriptor->supportedActions)->not->toBeEmpty();

        $ref = new PanelReference(providerKey: $descriptor->key, panelId: '1', parent: null);

        expect($panel->access($guest, $ref)->view)->toBeFalse()
            ->and($panel->access($member, $ref)->view)->toBeTrue()
            ->and($panel->access($member, $ref)->actions)->toBe($descriptor->supportedActions);
    }
});
