<?php

declare(strict_types=1);

namespace Commerz\Inventory\Items\Data;

use Commerz\Identity\EntityId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class StockItemId extends EntityId {}
