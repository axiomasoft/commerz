<?php

declare(strict_types=1);

namespace Commerz\Inventory\Movements\Contracts;

use Commerz\Attributes\Api;
use Commerz\Inventory\Movements\Data\ListMovements;
use Commerz\Inventory\Movements\Data\MovementView;
use Commerz\Pagination\Page;

/** The append-only stock journal: every on-hand change with the balance it left. Read only. */
#[Api(since: '0.1')]
interface Movements
{
    /** @return Page<MovementView> */
    public function list(ListMovements $query): Page;
}
