<?php

declare(strict_types=1);

namespace Commerz\Links\Enums;

use Commerz\Links\Contracts\LinkRegistry;
use Packman\Attributes\Api;

/**
 * Policy applied by {@see LinkRegistry::unlinkAllFor()} when an entity
 * participating in a link is unlinked or deleted.
 */
#[Api(since: '0.1')]
enum CascadeUnlinkPolicy: string
{
    case Unlink = 'unlink';
    case Restrict = 'restrict';
    case Ignore = 'ignore';
}
