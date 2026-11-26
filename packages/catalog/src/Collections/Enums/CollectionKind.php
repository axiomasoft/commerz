<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum CollectionKind: string
{
    case Manual = 'manual';
    case Smart = 'smart';
}
