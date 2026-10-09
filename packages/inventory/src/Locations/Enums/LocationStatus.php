<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Enums;

use Commerz\Attributes\Api;

/** `active ⇄ archived`; an archived location takes no new stock, sources or transfers. */
#[Api(since: '0.1')]
enum LocationStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
