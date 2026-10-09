<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

/** The sellable is linked to no stock item, and `commerz-inventory.unlinked_sellables` is `unavailable`. */
#[Api(since: '0.1')]
final class StockLinkMissing extends NotFound
{
    public function __construct(string $sellable)
    {
        parent::__construct('Stock link', $sellable, 'inventory.stock_link_missing');
    }
}
