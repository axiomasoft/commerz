<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Enums;

use Packman\Attributes\Api;

/** `active ⇄ archived`; an archived item takes no stock and no new links. */
#[Api(since: '0.1')]
enum StockItemStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
