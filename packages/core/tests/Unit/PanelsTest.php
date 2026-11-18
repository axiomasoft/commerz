<?php

declare(strict_types=1);

use Commerz\Panels\ChannelPanel;
use Commerz\Panels\StorePanel;
use Commerz\Plugins\CorePlugin;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;

it('exposes headless store and channel panels through CorePlugin', function (): void {
    expect((new CorePlugin)->panelProviders())->toBe([StorePanel::class, ChannelPanel::class]);

    $tenant = new readonly class implements ContextScopeInterface
    {
        public function id(): string
        {
            return 'default';
        }
    };

    $guest = new PanelContext(tenant: $tenant, actorKind: 'guest', actorId: null);
    $member = new PanelContext(tenant: $tenant, actorKind: 'user', actorId: '42');

    foreach ([new StorePanel, new ChannelPanel] as $panel) {
        $descriptor = $panel->descriptor();

        expect($descriptor->version)->toBe('panel/1')
            ->and($descriptor->owningModule)->toBe('axiomasoft/commerz')
            ->and($descriptor->supportedActions)->toBe(['view', 'create', 'update', 'archive']);

        $ref = new PanelReference(providerKey: $descriptor->key, panelId: '1', parent: null);

        expect($panel->access($guest, $ref)->view)->toBeFalse()
            ->and($panel->access($member, $ref)->view)->toBeFalse()
            ->and($panel->access($member, $ref)->actions)->toBe([]);
    }
});
