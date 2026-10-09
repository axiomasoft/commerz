<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\IdempotencyKey;
use Commerz\Inventory\Common\Data\InventoryCodes;
use Commerz\Inventory\Locations\Data\LocationId;

/** Drafts a transfer and reserves its lines at the source location. */
#[Api(since: '0.1')]
final readonly class CreateTransfer
{
    public const int MAX_LINES = 500;

    /** @param  list<TransferLine>  $lines  one line per stock item */
    public function __construct(
        public LocationId $from,
        public LocationId $to,
        public array $lines,
        public ?string $reference = null,
        public ?IdempotencyKey $idempotencyKey = null,
    ) {
        if ($from->equals($to)) {
            throw new InvalidCommand('to', 'must differ from the source location', 'inventory.invalid_transfer');
        }

        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw new InvalidCommand('lines', 'must hold 1 to '.self::MAX_LINES.' lines', 'inventory.invalid_transfer');
        }

        $seen = [];

        foreach ($lines as $line) {
            if (isset($seen[$line->item->value])) {
                throw new InvalidCommand('lines', "stock item '{$line->item->value}' is listed twice", 'inventory.invalid_transfer');
            }

            $seen[$line->item->value] = true;
        }

        if ($reference !== null) {
            InventoryCodes::reference($reference);
        }
    }
}
