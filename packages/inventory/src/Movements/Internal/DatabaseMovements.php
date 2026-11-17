<?php

declare(strict_types=1);

namespace Commerz\Inventory\Movements\Internal;

use Commerz\Inventory\Common\Internal\LevelQueries;
use Commerz\Inventory\Movements\Contracts\Movements;
use Commerz\Inventory\Movements\Data\ListMovements;
use Commerz\Pagination\Page;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseMovements implements Movements
{
    public function __construct(private LevelQueries $queries) {}

    public function list(ListMovements $query): Page
    {
        return $this->queries->movements($query);
    }
}
