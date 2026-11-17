<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Enums;

use Packman\Attributes\Api;

/** `draft ↔ active → archived`; archived is final. */
#[Api(since: '0.1')]
enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';
}
