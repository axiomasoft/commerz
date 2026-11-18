<?php

declare(strict_types=1);

namespace Commerz\Pricing\Panels;

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
 * Headless Packman panel provider descriptor and admission gate for tax zones.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class TaxZonesPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = ['view', 'define'];

    public function __construct(private ?Container $container = null) {}

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.pricing.tax_zones',
            owningModule: 'axiomasoft/commerz-pricing',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'Tax Zones',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return PanelAuthorization::evaluate($this->container, $context, $panel, $this->descriptor());
    }
}
