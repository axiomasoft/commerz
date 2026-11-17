<?php

declare(strict_types=1);

namespace Commerz\Sales\Panels;

use Commerz\Sales\Panels\Internal\SalesPanelGate;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;

/**
 * Headless Packman panel provider descriptor and admission gate for orders.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class OrdersPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = ['view', 'place', 'create_draft', 'cancel', 'cancel_lines', 'expire'];

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.sales.orders',
            owningModule: 'axiomasoft/commerz-sales',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'Orders',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return SalesPanelGate::evaluate($context, self::SUPPORTED_ACTIONS);
    }
}
