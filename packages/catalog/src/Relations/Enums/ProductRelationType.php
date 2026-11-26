<?php

declare(strict_types=1);

namespace Commerz\Catalog\Relations\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum ProductRelationType: string
{
    case Upsell = 'upsell';
    case CrossSell = 'cross_sell';
    case SparePart = 'spare_part';
    case Accessory = 'accessory';
    case Replacement = 'replacement';
}
