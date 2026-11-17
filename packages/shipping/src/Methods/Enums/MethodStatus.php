<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Enums;

use Packman\Attributes\Api;

/** Only an active method is offered. A disabled one can be enabled again; an archived one is final. */
#[Api(since: '0.1')]
enum MethodStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';
}
