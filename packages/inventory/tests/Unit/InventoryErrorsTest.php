<?php

declare(strict_types=1);

use Commerz\Errors\CommerzException;
use Commerz\Inventory\Allocation\Exceptions\InvalidAllocationPlan;
use Commerz\Inventory\Common\Exceptions\DuplicateCode;
use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Commerz\Inventory\Errors\InventoryProblemDefinitions;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Items\Exceptions\StockItemNotEmpty;
use Commerz\Inventory\Items\Exceptions\StockItemNotFound;
use Commerz\Inventory\Items\Exceptions\TooManyComponents;
use Commerz\Inventory\Items\Exceptions\UntrackedStockItem;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotEmpty;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Inventory\Reservations\Exceptions\ReservationConflict;
use Commerz\Inventory\Stock\Exceptions\NegativeStock;
use Commerz\Inventory\Stock\Exceptions\StaleStockCount;
use Commerz\Inventory\Stock\Exceptions\StockLinkMissing;
use Commerz\Inventory\Transfers\Enums\TransferState;
use Commerz\Inventory\Transfers\Exceptions\InvalidTransferState;
use Commerz\Inventory\Transfers\Exceptions\TransferNotFound;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

it('covers every InventoryErrorCode case with a matching ProblemDefinition', function (): void {
    $definitions = (new InventoryProblemDefinitions)->definitions();
    $codes = array_map(static fn (ProblemDefinition $d): string => $d->code->value(), $definitions);
    $expected = array_column(InventoryErrorCode::cases(), 'value');

    sort($codes);
    sort($expected);

    expect($codes)->toBe($expected);
});

it('exposes Packman error descriptors on inventory exceptions', function (CommerzException $e, InventoryErrorCode $code, ErrorCategory $category): void {
    $descriptor = $e->errorDescriptor();

    expect($descriptor->errorCode)->toBe($code)
        ->and($descriptor->category)->toBe($category);
})->with([
    'invalid allocation plan' => [new InvalidAllocationPlan('bad'), InventoryErrorCode::InvalidAllocationPlan, ErrorCategory::Unavailable],
    'duplicate code' => [new DuplicateCode('Location', 'main'), InventoryErrorCode::DuplicateCode, ErrorCategory::Conflict],
    'stock item archived' => [new StockItemArchived('TEE'), InventoryErrorCode::StockItemArchived, ErrorCategory::Conflict],
    'stock item not empty' => [new StockItemNotEmpty('TEE'), InventoryErrorCode::StockItemNotEmpty, ErrorCategory::Conflict],
    'stock item not found' => [new StockItemNotFound('TEE'), InventoryErrorCode::StockItemNotFound, ErrorCategory::NotFound],
    'too many components' => [new TooManyComponents('kit:1'), InventoryErrorCode::TooManyComponents, ErrorCategory::Conflict],
    'untracked stock item' => [new UntrackedStockItem('EBOOK'), InventoryErrorCode::UntrackedStockItem, ErrorCategory::Conflict],
    'location archived' => [new LocationArchived('main'), InventoryErrorCode::LocationArchived, ErrorCategory::Conflict],
    'location not empty' => [new LocationNotEmpty('main'), InventoryErrorCode::LocationNotEmpty, ErrorCategory::Conflict],
    'location not found' => [new LocationNotFound('main'), InventoryErrorCode::LocationNotFound, ErrorCategory::NotFound],
    'reservation conflict' => [new ReservationConflict('order:1', 'web'), InventoryErrorCode::ReservationConflict, ErrorCategory::Conflict],
    'negative stock' => [new NegativeStock('TEE', 'main', 2, -5), InventoryErrorCode::NegativeStock, ErrorCategory::Conflict],
    'stale stock count' => [new StaleStockCount('TEE', 'main', 2, 1), InventoryErrorCode::StaleStockCount, ErrorCategory::Conflict],
    'stock link missing' => [new StockLinkMissing('variant:1'), InventoryErrorCode::StockLinkMissing, ErrorCategory::NotFound],
    'invalid transfer state' => [new InvalidTransferState('t1', TransferState::Completed, 'dispatch'), InventoryErrorCode::InvalidTransferState, ErrorCategory::Conflict],
    'transfer not found' => [new TransferNotFound('t1'), InventoryErrorCode::TransferNotFound, ErrorCategory::NotFound],
]);
