<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Enums;

use Packman\Attributes\Api;

/** Where an attribute value lives: on the product or on each variant. */
#[Api(since: '0.1')]
enum AttributeLevel: string
{
    case Product = 'product';
    case Variant = 'variant';
}
