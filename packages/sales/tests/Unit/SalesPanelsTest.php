<?php

declare(strict_types=1);

use Commerz\Sales\Panels\FulfillmentsPanel;
use Commerz\Sales\Panels\OrdersPanel;
use Commerz\Sales\Panels\PaymentsPanel;
use Commerz\Sales\Panels\ReturnsPanel;
use Commerz\Sales\Plugins\SalesPlugin;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;

it('exposes headless sales panels through SalesPlugin', function (): void {
    expect((new SalesPlugin)->panelProviders())->toBe([
        OrdersPanel::class,
        PaymentsPanel::class,
        FulfillmentsPanel::class,
        ReturnsPanel::class,
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

    foreach ([new OrdersPanel, new PaymentsPanel, new FulfillmentsPanel, new ReturnsPanel] as $panel) {
        $descriptor = $panel->descriptor();

        expect($descriptor->version)->toBe('panel/1')
            ->and($descriptor->owningModule)->toBe('axiomasoft/commerz-sales')
            ->and($descriptor->supportedActions)->not->toBeEmpty();

        $ref = new PanelReference(providerKey: $descriptor->key, panelId: '1', parent: null);

        expect($panel->access($guest, $ref)->view)->toBeFalse()
            ->and($panel->access($member, $ref)->view)->toBeFalse()
            ->and($panel->access($member, $ref)->actions)->toBe([]);
    }
});
