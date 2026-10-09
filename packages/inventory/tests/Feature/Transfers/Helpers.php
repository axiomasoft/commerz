<?php

declare(strict_types=1);

// Shared fixtures of the transfer tests; builds on the reservation helpers.

use Commerz\Identity\IdempotencyKey;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Transfers\Data\CreateTransfer;
use Commerz\Inventory\Transfers\Data\ReceiveTransfer;
use Commerz\Inventory\Transfers\Data\TransferLine;
use Commerz\Inventory\Transfers\Data\TransferReceipt;
use Commerz\Inventory\Transfers\Data\TransferView;

require_once __DIR__.'/../Reservations/Helpers.php';

/** @param  array<string, int>  $lines  stock item id => quantity */
function trfCreate(LocationId $from, LocationId $to, array $lines, ?string $reference = null, ?IdempotencyKey $key = null): TransferView
{
    return Inventory::transfers()->create(new CreateTransfer(
        $from,
        $to,
        array_map(static fn (string $item, int $quantity): TransferLine => new TransferLine(new StockItemId($item), $quantity), array_keys($lines), $lines),
        $reference,
        $key,
    ));
}

/** @param  array<string, array{0: int, 1?: int}>  $receipts  stock item id => [received, lost] */
function trfReceive(TransferView $transfer, array $receipts, ?IdempotencyKey $key = null): TransferView
{
    return Inventory::transfers()->receive(new ReceiveTransfer(
        $transfer->id,
        array_map(static fn (string $item, array $units): TransferReceipt => new TransferReceipt(new StockItemId($item), $units[0], $units[1] ?? 0), array_keys($receipts), $receipts),
        $key,
    ));
}

/** @return array{LocationId, LocationId, StockItemId} source with 10 units, an empty destination, the item */
function trfWorld(int $stock = 10): array
{
    $from = Inventory::locations()->create(new CreateLocation('main', 'Main'))->id;
    $to = Inventory::locations()->create(new CreateLocation('shop', 'Shop'))->id;
    $item = rsvItem('tee', ['tee' => 1]);
    rsvReceive($item, $from, $stock);

    return [$from, $to, $item];
}
