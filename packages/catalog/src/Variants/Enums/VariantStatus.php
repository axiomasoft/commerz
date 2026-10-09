<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Enums;

use Commerz\Attributes\Api;

/** `active ↔ archived`; an archived variant keeps its combination taken and comes back through `restore`. */
#[Api(since: '0.1')]
enum VariantStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
