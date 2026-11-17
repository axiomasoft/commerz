<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Internal;

use Closure;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Inventory\Common\Internal\LevelQueries;
use Commerz\Inventory\Common\Internal\LevelRow;
use Commerz\Inventory\Common\Internal\StockLedger;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Stock\Contracts\StockLevels;
use Commerz\Inventory\Stock\Data\AdjustStock;
use Commerz\Inventory\Stock\Data\CountStock;
use Commerz\Inventory\Stock\Data\ListStockLevels;
use Commerz\Inventory\Stock\Data\ReceiveStock;
use Commerz\Inventory\Stock\Data\SetSafetyStock;
use Commerz\Inventory\Stock\Data\StockLevelView;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Inventory\Stock\Exceptions\NegativeStock;
use Commerz\Inventory\Stock\Exceptions\StaleStockCount;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Pagination\Page;
use DateTimeImmutable;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * Lock order: the item row, then the location row (both `for share`, so archiving either waits), then the level
 * through the ledger. Every precondition is checked against the locked level; `count` compares `expectedOnHand` with
 * the on-hand under the lock, never with an earlier read.
 */
#[Internal]
final readonly class DatabaseStockLevels implements StockLevels
{
    public function __construct(
        private Transactions $transactions,
        private OperationReceipts $receipts,
        private StockLedger $ledger,
        private LevelQueries $queries,
        private StockEntities $entities,
    ) {}

    public function receive(ReceiveStock $command): StockLevelView
    {
        return $this->keyed('inventory.receive', $command->idempotencyKey, [
            'item' => $command->item->value,
            'location' => $command->location->value,
            'quantity' => $command->quantity,
            'reference' => $command->reference,
            'reason' => $command->reason,
            'actor' => self::actor($command->actor),
        ], fn (): StockLevelView => $this->change(
            $command->item,
            $command->location,
            static fn (): int => $command->quantity,
            MovementKind::Receipt,
            $command->reference,
            $command->reason,
            $command->actor,
        ));
    }

    public function adjust(AdjustStock $command): StockLevelView
    {
        return $this->keyed('inventory.adjust', $command->idempotencyKey, [
            'item' => $command->item->value,
            'location' => $command->location->value,
            'delta' => $command->delta,
            'reference' => $command->reference,
            'reason' => $command->reason,
            'actor' => self::actor($command->actor),
        ], fn (): StockLevelView => $this->change(
            $command->item,
            $command->location,
            static fn (): int => $command->delta,
            MovementKind::Adjustment,
            $command->reference,
            $command->reason,
            $command->actor,
        ));
    }

    public function count(CountStock $command): StockLevelView
    {
        return $this->keyed('inventory.count', $command->idempotencyKey, [
            'item' => $command->item->value,
            'location' => $command->location->value,
            'counted' => $command->counted,
            'expected_on_hand' => $command->expectedOnHand,
            'reference' => $command->reference,
            'reason' => $command->reason,
            'actor' => self::actor($command->actor),
        ], fn (): StockLevelView => $this->change(
            $command->item,
            $command->location,
            static function (LevelRow $level) use ($command): int {
                if ($command->expectedOnHand !== null && $command->expectedOnHand !== $level->onHand) {
                    throw new StaleStockCount($level->sku, $level->locationCode, $command->expectedOnHand, $level->onHand);
                }

                return $command->counted - $level->onHand;
            },
            MovementKind::Count,
            $command->reference,
            $command->reason,
            $command->actor,
        ));
    }

    public function setSafetyStock(SetSafetyStock $command): StockLevelView
    {
        return $this->transactions->run(fn (): StockLevelView => $this->ledger->within(function () use ($command): StockLevelView {
            $level = $this->lockLevel($command->item, $command->location);

            if ($command->quantity === $level->safetyStock) {
                return $level->view();
            }

            if ($command->quantity > $level->safetyStock) {
                self::assertActive($level);
            }

            return $this->ledger->setSafety($level, $command->quantity)->view();
        }));
    }

    public function level(StockItemId $item, LocationId $location): StockLevelView
    {
        return $this->queries->level($item, $location);
    }

    public function list(ListStockLevels $query): Page
    {
        return $this->queries->levels($query);
    }

    /** @param  Closure(LevelRow): int  $delta  the on-hand change, decided under the level lock; 0 changes nothing */
    private function change(
        StockItemId $item,
        LocationId $location,
        Closure $delta,
        MovementKind $kind,
        ?string $reference,
        ?string $reason,
        ?ActorRef $actor,
    ): StockLevelView {
        return $this->transactions->run(fn (): StockLevelView => $this->ledger->within(function () use ($item, $location, $delta, $kind, $reference, $reason, $actor): StockLevelView {
            $level = $this->lockLevel($item, $location);
            $change = $delta($level);

            if ($change === 0) {
                return $level->view();
            }

            if ($change > 0) {
                self::assertActive($level);
            } elseif ($change < -$level->onHand) {
                throw new NegativeStock($level->sku, $level->locationCode, $level->onHand, $change);
            }

            return $this->ledger->move($level, $change, $kind, $reference, $reason, $actor)->view();
        }));
    }

    private function lockLevel(StockItemId $item, LocationId $location): LevelRow
    {
        $this->entities->lockTracked([$item->value], $location->value);
        $pair = [$item->value, $location->value];
        $this->ledger->ensureLevels([$pair]);

        return $this->ledger->lock([$pair])[LevelRow::key(...$pair)]
            ?? throw new RuntimeException("The level of {$item->value} at {$location->value} vanished under its locks.");
    }

    /** Archived items and locations take no new stock: only changes that lower on-hand or safety stock pass. */
    private static function assertActive(LevelRow $level): void
    {
        if ($level->itemArchived) {
            throw new StockItemArchived($level->sku);
        }

        if ($level->locationArchived) {
            throw new LocationArchived($level->locationCode);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): StockLevelView  $work
     */
    private function keyed(string $scope, ?IdempotencyKey $key, array $payload, Closure $work): StockLevelView
    {
        if (! $key instanceof IdempotencyKey) {
            return $work();
        }

        return $this->receipts->once($scope, $key, Fingerprint::of($payload), $work, self::encode(...), self::decode(...));
    }

    private static function actor(?ActorRef $actor): ?string
    {
        return $actor instanceof ActorRef ? (string) $actor : null;
    }

    /** @return array<string, int|string|null> */
    private static function encode(StockLevelView $view): array
    {
        return [
            'item' => $view->item->value,
            'sku' => $view->sku,
            'location' => $view->location->value,
            'location_code' => $view->locationCode,
            'on_hand' => $view->onHand,
            'reserved' => $view->reserved,
            'safety_stock' => $view->safetyStock,
            'version' => $view->version,
            'updated_at' => $view->updatedAt?->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    /** @param  array<array-key, mixed>  $stored */
    private static function decode(array $stored): StockLevelView
    {
        $string = static fn (string $key): string => is_string($stored[$key] ?? null) ? $stored[$key] : throw new RuntimeException("Stored stock level lacks '{$key}'.");
        $int = static fn (string $key): int => is_int($stored[$key] ?? null) ? $stored[$key] : throw new RuntimeException("Stored stock level lacks '{$key}'.");
        $updatedAt = $stored['updated_at'] ?? null;

        return new StockLevelView(
            new StockItemId($string('item')),
            $string('sku'),
            new LocationId($string('location')),
            $string('location_code'),
            $int('on_hand'),
            $int('reserved'),
            $int('safety_stock'),
            $int('version'),
            is_string($updatedAt) ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
