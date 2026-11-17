<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Items\Data\StockItemId;
use Packman\Attributes\Api;

/** What arrived (`received`, put on hand at the destination) and what is written off (`lost`) of one line. */
#[Api(since: '0.1')]
final readonly class TransferReceipt
{
    public function __construct(public StockItemId $item, public int $received, public int $lost = 0)
    {
        if ($received < 0 || $lost < 0) {
            throw new InvalidCommand('quantity', 'received and lost are zero or more', 'inventory.invalid_quantity');
        }
    }
}
