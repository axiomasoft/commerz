<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Panels;

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
 * Headless Packman panel provider descriptor and admission gate for subscription contracts and billing cycles.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class SubscriptionsPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = [
        'view',
        'pause',
        'resume',
        'cancel',
        'retry_billing_cycle',
        'update_payment_method',
    ];

    public function __construct(private ?Container $container = null) {}

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.subscriptions.contracts',
            owningModule: 'axiomasoft/commerz-subscriptions',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'Subscriptions',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return PanelAuthorization::evaluate($this->container, $context, $panel, $this->descriptor());
    }
}
