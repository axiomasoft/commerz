<?php

declare(strict_types=1);

namespace Commerz\Promotions\Panels;

use Commerz\Promotions\Panels\Internal\PromotionsPanelGate;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;

/**
 * Headless Packman panel provider descriptor and admission gate for promotions.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class PromotionsPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = [
        'view',
        'create',
        'update',
        'pause',
        'resume',
        'archive',
        'assign_channels',
        'unassign_channels',
    ];

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.promotions.promotions',
            owningModule: 'axiomasoft/commerz-promotions',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'Promotions',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return PromotionsPanelGate::evaluate($context, self::SUPPORTED_ACTIONS);
    }
}
