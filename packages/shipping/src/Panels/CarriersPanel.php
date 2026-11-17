<?php

declare(strict_types=1);

namespace Commerz\Shipping\Panels;

use Commerz\Shipping\Panels\Internal\ShippingPanelGate;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;

/**
 * Headless Packman panel provider descriptor and admission gate for shipping carriers and bookings.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class CarriersPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = ['view', 'book'];

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.shipping.carriers',
            owningModule: 'axiomasoft/commerz-shipping',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'Shipping Carriers',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return ShippingPanelGate::evaluate($context, self::SUPPORTED_ACTIONS);
    }
}
