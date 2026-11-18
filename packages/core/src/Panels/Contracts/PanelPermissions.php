<?php

declare(strict_types=1);

namespace Commerz\Panels\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelReference;

/** Host policy for the trusted actor, tenant, panel resource and individual action. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface PanelPermissions
{
    public function allows(PanelContext $context, PanelReference $panel, string $action): bool;
}
