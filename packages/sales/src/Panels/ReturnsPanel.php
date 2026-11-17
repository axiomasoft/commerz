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
 * Headless Packman panel provider descriptor and admission gate for returns.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class ReturnsPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = ['view', 'request', 'receive', 'cancel'];

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.sales.returns',
            owningModule: 'axiomasoft/commerz-sales',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'Returns',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return SalesPanelGate::evaluate($context, self::SUPPORTED_ACTIONS);
    }
}
