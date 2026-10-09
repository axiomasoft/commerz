<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Enums;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
enum ProductTypeStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
