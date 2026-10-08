<?php

declare(strict_types=1);

namespace Commerz\Configuration;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
enum StockMode: string
{
    /** Sales are checked against the inventory package. */
    case Inventory = 'inventory';

    /** Everything is always available; no stock is tracked. */
    case Unlimited = 'unlimited';
}
