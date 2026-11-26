<?php

declare(strict_types=1);

namespace Commerz\B2b\Panels;

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
 * Headless Packman panel provider descriptor and admission gate for B2B organizations, quotes, and approvals.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class B2bPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = [
        'view',
        'manage_business_units',
        'manage_associates',
        'manage_payment_terms',
        'manage_credit_accounts',
        'stage_quotes',
        'approve_flows',
    ];

    public function __construct(private ?Container $container = null) {}

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.b2b.commerce',
            owningModule: 'axiomasoft/commerz-b2b',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'B2B Commerce',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return PanelAuthorization::evaluate($this->container, $context, $panel, $this->descriptor());
    }
}
