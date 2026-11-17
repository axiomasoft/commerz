<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\IdempotencyKey;
use Packman\Attributes\Api;

/**
 * Receives the shipped units at the destination, in full or in part. Per line `received + lost` cannot exceed what is
 * still in transit; the transfer completes once every line is settled. With a key a repeat returns the first result.
 */
#[Api(since: '0.1')]
final readonly class ReceiveTransfer
{
    /** @param  list<TransferReceipt>  $lines  one receipt per stock item */
    public function __construct(
        public TransferId $transfer,
        public array $lines,
        public ?IdempotencyKey $idempotencyKey = null,
    ) {
        if ($lines === [] || count($lines) > CreateTransfer::MAX_LINES) {
            throw new InvalidCommand('lines', 'must hold 1 to '.CreateTransfer::MAX_LINES.' receipts', 'inventory.invalid_transfer');
        }

        $seen = [];

        foreach ($lines as $line) {
            if (isset($seen[$line->item->value])) {
                throw new InvalidCommand('lines', "stock item '{$line->item->value}' is listed twice", 'inventory.invalid_transfer');
            }

            $seen[$line->item->value] = true;
        }

        if (array_all($lines, static fn (TransferReceipt $line): bool => $line->received === 0 && $line->lost === 0)) {
            throw new InvalidCommand('lines', 'must receive or write off at least one unit', 'inventory.invalid_transfer');
        }
    }
}
