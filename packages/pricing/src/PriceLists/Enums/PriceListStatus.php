<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum PriceListStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
