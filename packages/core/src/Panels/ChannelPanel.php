<?php

declare(strict_types=1);

namespace Commerz\Panels;

use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;

/**
 * Headless Packman panel provider descriptor and admission gate for Commerz channels.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class ChannelPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = ['view', 'create', 'update', 'archive'];

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.channels',
            owningModule: 'axiomasoft/commerz',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'Channels',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        if ($context->actorKind === 'guest') {
            return PanelAccess::deny('unauthenticated_actor');
        }

        return new PanelAccess(
            view: true,
            actions: self::SUPPORTED_ACTIONS,
            reason: null,
        );
    }
}
