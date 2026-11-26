<?php

declare(strict_types=1);

namespace Commerz\Catalog\Brands\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum BrandStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
