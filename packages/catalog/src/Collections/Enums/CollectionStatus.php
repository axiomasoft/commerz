<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum CollectionStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
