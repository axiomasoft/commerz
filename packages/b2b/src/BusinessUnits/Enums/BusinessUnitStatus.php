<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum BusinessUnitStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';
}
