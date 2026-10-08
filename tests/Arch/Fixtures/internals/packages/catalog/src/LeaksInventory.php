<?php

declare(strict_types=1);

namespace Commerz\Catalog;

use Commerz\Attributes\Api;
use Commerz\Inventory\Reservations\Internal\ReserveStockHandler;

#[Api(since: '0.1')]
final class LeaksInventory
{
    public function handler(): string
    {
        return ReserveStockHandler::class;
    }
}
