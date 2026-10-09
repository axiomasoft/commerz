<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Enums;

use Commerz\Attributes\Api;

/** `active → archived`; archived is final and takes no products, children or changes. */
#[Api(since: '0.1')]
enum CategoryStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
