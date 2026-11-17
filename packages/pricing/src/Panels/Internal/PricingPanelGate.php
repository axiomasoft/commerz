<?php

declare(strict_types=1);

namespace Commerz\Pricing\Panels\Internal;

use Packman\Attributes\Internal;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;

/**
 * Shared headless admission policy for pricing panel providers.
 */
#[Internal]
final readonly class PricingPanelGate
{
    /** @param list<string> $supportedActions */
    public static function evaluate(PanelContext $context, array $supportedActions): PanelAccess
    {
        if ($context->actorKind === 'guest') {
            return PanelAccess::deny('unauthenticated_actor');
        }

        return new PanelAccess(
            view: true,
            actions: $supportedActions,
            reason: null,
        );
    }
}
