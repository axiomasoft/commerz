<?php

declare(strict_types=1);

namespace Commerz\Pricing\Panels;

use Commerz\Pricing\Panels\Internal\PricingPanelGate;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;

/**
 * Headless Packman panel provider descriptor and admission gate for tax rates.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class TaxRatesPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = ['view', 'set', 'reschedule', 'remove'];

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.pricing.tax_rates',
            owningModule: 'axiomasoft/commerz-pricing',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'Tax Rates',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return PricingPanelGate::evaluate($context, self::SUPPORTED_ACTIONS);
    }
}
