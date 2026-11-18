<?php

declare(strict_types=1);

namespace Commerz\Inventory\Panels;

use Commerz\Panels\PanelAuthorization;
use Illuminate\Contracts\Container\Container;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;

/**
 * Headless Packman panel provider descriptor and admission gate for stock levels.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class StockLevelsPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = ['view', 'receive', 'adjust', 'count', 'set_safety_stock'];

    public function __construct(private ?Container $container = null) {}

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.inventory.stock_levels',
            owningModule: 'axiomasoft/commerz-inventory',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'Stock Levels',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return PanelAuthorization::evaluate($this->container, $context, $panel, $this->descriptor());
    }
}
