<?php

declare(strict_types=1);

namespace Commerz\Panels;

use Commerz\Panels\Contracts\PanelPermissions;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Api;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;
use Throwable;

/** Shared fail-closed admission policy for the Commerz headless panel providers. */
#[Api(since: '0.1')]
final readonly class PanelAuthorization
{
    public static function evaluate(?Container $container, PanelContext $context, PanelReference $panel, PanelDescriptor $descriptor): PanelAccess
    {
        if ($context->actorKind === 'guest') {
            return PanelAccess::deny('unauthenticated_actor');
        }

        if ($panel->providerKey !== $descriptor->key) {
            return PanelAccess::deny('panel_mismatch');
        }

        if ($container === null || ! $container->bound(PanelPermissions::class)) {
            return PanelAccess::deny('permissions_unavailable');
        }

        try {
            return self::authorize($container->make(PanelPermissions::class), $context, $panel, $descriptor);
        } catch (Throwable) {
            return PanelAccess::deny('permissions_unavailable');
        }
    }

    private static function authorize(PanelPermissions $permissions, PanelContext $context, PanelReference $panel, PanelDescriptor $descriptor): PanelAccess
    {
        if (! $permissions->allows($context, $panel, 'view')) {
            return PanelAccess::deny('permission_denied');
        }

        $actions = array_values(array_filter(
            $descriptor->supportedActions,
            static fn (string $action): bool => $action === 'view' || $permissions->allows($context, $panel, $action),
        ));

        return new PanelAccess(view: true, actions: $actions, reason: null);
    }
}
