<?php

declare(strict_types=1);

namespace Commerz\Inventory\Panels;

use Commerz\Inventory\Panels\Internal\InventoryPanelGate;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;

/**
 * Headless Packman panel provider descriptor and admission gate for stock locations.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class StockLocationsPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = ['view', 'create', 'update', 'archive', 'restore'];

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.inventory.stock_locations',
            owningModule: 'axiomasoft/commerz-inventory',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'Stock Locations',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return InventoryPanelGate::evaluate($context, self::SUPPORTED_ACTIONS);
    }
}
