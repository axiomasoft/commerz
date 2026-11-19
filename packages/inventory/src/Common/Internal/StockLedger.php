<?php

declare(strict_types=1);

namespace Commerz\Inventory\Common\Internal;

use Closure;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\Uuid7;
use Commerz\Inventory\Items\Enums\StockItemStatus;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Locations\Enums\LocationStatus;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Inventory\Stock\Events\StockLevelChanged;
use Commerz\Inventory\Stock\Events\StockShortfall;
use Commerz\Inventory\Stock\Exceptions\NegativeStock;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Packman\Attributes\Internal;

/**
 * The only writer of `stock_levels` and `stock_movements` (INV-03: every on-hand change is exactly one movement whose
 * `balance_after` is the new on-hand, in the same transaction). Every quantity change is a conditional
 * `UPDATE … RETURNING` on a level the caller locked through {@see lock()}; there is no read-then-write in PHP.
 *
 * Mutations run inside {@see within()}, one scope per command: at its end the ledger records one
 * `inventory.level_changed` outbox event per changed level (final state) and queues `StockLevelChanged` and, when the
 * level got shorter, `StockShortfall` for after commit. A failed scope publishes nothing; its writes roll back with the
 * caller's transaction or savepoint.
 */
#[Internal]
final class StockLedger
{
    /** @var array<int, array<string, array{before: LevelRow, after: LevelRow, delta: int, kind: ?MovementKind, reference: ?string}>> */
    private array $scopes = [];

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly CommerzOptions $config,
        private readonly TableNames $tables,
        private readonly Outbox $outbox,
        private readonly CommittedEvents $events,
    ) {}

    /**
     * Runs one command's ledger work and publishes its level changes when it returns.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function within(Closure $work): mixed
    {
        $this->assertTransaction();
        $depth = count($this->scopes);
        $this->scopes[] = [];

        try {
            $result = $work();
            $this->publish($this->scopes[$depth]);

            return $result;
        } finally {
            array_splice($this->scopes, $depth);
        }
    }

    /**
     * Creates the missing levels of the pairs at zero; existing ones are left alone.
     *
     * @param  list<array{0: string, 1: string}>  $pairs  `[stock item id, location id]`
     */
    public function ensureLevels(array $pairs): void
    {
        $this->assertTransaction();
        $now = Clock::now();
        $rows = [];

        foreach (self::sorted($pairs) as [$item, $location]) {
            $rows[] = ['id' => Uuid7::generate(), 'stock_item_id' => $item, 'location_id' => $location, 'created_at' => $now, 'updated_at' => $now];
        }

        if ($rows !== []) {
            $this->db()->table($this->tables->table('stock_levels'))->insertOrIgnore($rows);
        }
    }

    /**
     * Locks the existing levels of the pairs in one statement, ordered by `(stock_item_id, location_id)`, `for no key
     * update` (movement and reservation rows reference a level and take `for key share` on it). Lock every level a
     * command needs here, once: a second lock call in the same command can deadlock against another command.
     *
     * @param  list<array{0: string, 1: string}>  $pairs  `[stock item id, location id]`
     * @return array<string, LevelRow> by {@see LevelRow::key()}; a pair without a level is absent
     */
    public function lock(array $pairs): array
    {
        $this->assertTransaction();
        $pairs = self::sorted($pairs);

        if ($pairs === []) {
            return [];
        }

        $levels = $this->tables->table('stock_levels');
        $items = $this->tables->table('stock_items');
        $locations = $this->tables->table('stock_locations');
        $tuples = implode(', ', array_fill(0, count($pairs), '(?::uuid, ?::uuid)'));

        $rows = $this->db()->select(
            'select lv.id, lv.stock_item_id, lv.location_id, lv.on_hand, lv.reserved, lv.safety_stock, lv.version,'
            .' lv.updated_at, i.sku, i.policy, i.status as item_status, l.code as location_code, l.status as location_status'
            ." from {$levels} lv join {$items} i on i.id = lv.stock_item_id join {$locations} l on l.id = lv.location_id"
            ." where (lv.stock_item_id, lv.location_id) in ({$tuples})"
            .' order by lv.stock_item_id, lv.location_id for no key update of lv',
            array_merge(...$pairs),
            useReadPdo: false,
        );

        $locked = [];

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $level = new LevelRow(
                Rows::string($row, 'id'),
                Rows::string($row, 'stock_item_id'),
                Rows::string($row, 'location_id'),
                Rows::string($row, 'sku'),
                Rows::string($row, 'location_code'),
                StockPolicy::from(Rows::string($row, 'policy')),
                Rows::string($row, 'item_status') === StockItemStatus::Archived->value,
                Rows::string($row, 'location_status') === LocationStatus::Archived->value,
                Rows::int($row, 'on_hand'),
                Rows::int($row, 'reserved'),
                Rows::int($row, 'safety_stock'),
                Rows::int($row, 'version'),
                Rows::string($row, 'updated_at'),
            );
            $locked[$level->levelKey()] = $level;
        }

        return $locked;
    }

    /**
     * Changes on-hand by `$delta` and journals it.
     *
     * @throws NegativeStock when on-hand would drop below zero
     */
    public function move(LevelRow $level, int $delta, MovementKind $kind, ?string $reference = null, ?string $reason = null, ?ActorRef $actor = null): LevelRow
    {
        if ($delta === 0 || $delta === PHP_INT_MIN) {
            throw new LogicException('A stock movement changes on-hand by a non-zero int64.');
        }

        if ($delta > 0 && Quantities::add($level->onHand, $delta) === null) {
            throw new InvalidCommand('quantity', 'overflows int64', 'inventory.invalid_quantity');
        }

        $returned = $this->update(
            $level,
            'on_hand = on_hand + ?',
            [$delta],
            'on_hand + ? >= 0',
            [$delta],
        ) ?? throw new NegativeStock($level->sku, $level->locationCode, $this->current($level)->onHand, $delta);

        return $this->journal($level, $returned, $delta, $kind, $reference, $reason, $actor);
    }

    /**
     * Reserves `$quantity` of the level. Without `$backorder` only free stock (`on_hand − reserved − safety_stock`)
     * can be reserved; callers allocate from locked levels, so the guard failing is a programming error made safe.
     *
     * @throws InsufficientStock
     */
    public function hold(LevelRow $level, int $quantity, bool $backorder): LevelRow
    {
        self::assertQuantity($quantity);

        if (Quantities::add($this->current($level)->reserved, $quantity) === null) {
            throw new InvalidCommand('quantity', 'overflows int64', 'inventory.invalid_quantity');
        }

        $returned = $this->update(
            $level,
            'reserved = reserved + ?',
            [$quantity],
            '(? = 1 or on_hand::numeric - reserved - safety_stock >= ?)',
            [$backorder ? 1 : 0, $quantity],
        ) ?? throw new InsufficientStock('stock_item:'.$level->item, $quantity, max(0, $this->current($level)->view()->available));

        return $this->track($level, $level->with($returned), 0, null, null);
    }

    /** Gives `$quantity` of the level's reservation back; releasing more than is reserved is a programming error. */
    public function unhold(LevelRow $level, int $quantity): LevelRow
    {
        self::assertQuantity($quantity);

        $returned = $this->update($level, 'reserved = reserved - ?', [$quantity], 'reserved >= ?', [$quantity])
            ?? throw new LogicException("Level {$level->id} reserves fewer than {$quantity} units.");

        return $this->track($level, $level->with($returned), 0, null, null);
    }

    /**
     * Takes reserved units off hand: `on_hand` and `reserved` both drop by `$quantity` (movement `sale` or
     * `transfer_out`).
     *
     * @throws InsufficientStock when the units are reserved but not physically on hand (a backorder not yet received)
     */
    public function ship(LevelRow $level, int $quantity, MovementKind $kind, ?string $reference = null, ?ActorRef $actor = null): LevelRow
    {
        self::assertQuantity($quantity);

        $returned = $this->update(
            $level,
            'on_hand = on_hand - ?, reserved = reserved - ?',
            [$quantity, $quantity],
            'on_hand >= ? and reserved >= ?',
            [$quantity, $quantity],
        );

        if ($returned === null) {
            $current = $this->current($level);

            if ($current->reserved < $quantity) {
                throw new LogicException("Level {$level->id} reserves fewer than {$quantity} units.");
            }

            throw new InsufficientStock('stock_item:'.$level->item, $quantity, $current->onHand);
        }

        return $this->journal($level, $returned, -$quantity, $kind, $reference, null, $actor);
    }

    /**
     * Sets the level's `reserved` to `$quantity`, the sum its outstanding reservation lines hold: the reconciler's
     * only repair. On-hand is never touched here; it is corrected by a count.
     */
    public function repairReserved(LevelRow $level, int $quantity): LevelRow
    {
        if ($quantity < 0) {
            throw new LogicException('Reserved stock is zero or more.');
        }

        $returned = $this->update($level, 'reserved = ?', [$quantity], 'true', [])
            ?? throw new LogicException("Level {$level->id} vanished under its lock.");

        return $this->track($level, $level->with($returned), 0, null, null);
    }

    public function setSafety(LevelRow $level, int $quantity): LevelRow
    {
        if ($quantity < 0) {
            throw new LogicException('Safety stock is zero or more.');
        }

        $returned = $this->update($level, 'safety_stock = ?', [$quantity], 'true', [])
            ?? throw new LogicException("Level {$level->id} vanished under its lock.");

        return $this->track($level, $level->with($returned), 0, null, null);
    }

    /**
     * @param  list<int>  $setBindings
     * @param  list<int>  $guardBindings
     */
    private function update(LevelRow $level, string $set, array $setBindings, string $guard, array $guardBindings): ?object
    {
        $this->assertTransaction();
        $this->assertScope();
        $table = $this->tables->table('stock_levels');

        $row = $this->db()->selectOne(
            "update {$table} set {$set}, version = version + 1, updated_at = ? where id = ? and {$guard}"
            .' returning on_hand, reserved, safety_stock, version, updated_at',
            [...$setBindings, Clock::now(), $level->id, ...$guardBindings],
            useReadPdo: false,
        );

        return is_object($row) ? $row : null;
    }

    private function journal(LevelRow $level, object $returned, int $delta, MovementKind $kind, ?string $reference, ?string $reason, ?ActorRef $actor): LevelRow
    {
        $after = $level->with($returned);

        $this->db()->table($this->tables->table('stock_movements'))->insert([
            'stock_level_id' => $level->id,
            'stock_item_id' => $level->item,
            'location_id' => $level->location,
            'kind' => $kind->value,
            'delta' => $delta,
            'balance_after' => $after->onHand,
            'reference' => $reference,
            'reason' => $reason,
            'actor_ref' => $actor instanceof ActorRef ? (string) $actor : null,
            'occurred_at' => $after->updatedAt,
        ]);

        return $this->track($level, $after, $delta, $kind, $reference);
    }

    private function track(LevelRow $before, LevelRow $after, int $delta, ?MovementKind $kind, ?string $reference): LevelRow
    {
        $scope = count($this->scopes) - 1;
        $previous = $this->scopes[$scope][$before->id] ?? null;

        $this->scopes[$scope][$before->id] = [
            'before' => $previous['before'] ?? $before,
            'after' => $after,
            // A net delta beyond int64 cannot happen: on-hand itself stays within 0..PHP_INT_MAX.
            'delta' => ($previous['delta'] ?? 0) + $delta,
            'kind' => $kind ?? $previous['kind'] ?? null,
            'reference' => $kind instanceof MovementKind ? $reference : $previous['reference'] ?? null,
        ];

        return $after;
    }

    /** The level's latest state in this command: what the ledger last wrote, or what the caller locked. */
    private function current(LevelRow $level): LevelRow
    {
        return $this->scopes[count($this->scopes) - 1][$level->id]['after'] ?? $level;
    }

    /** @param  array<string, array{before: LevelRow, after: LevelRow, delta: int, kind: ?MovementKind, reference: ?string}>  $changes */
    private function publish(array $changes): void
    {
        foreach ($changes as $change) {
            $after = $change['after'];
            $view = $after->view();

            $this->outbox->record(IntegrationEvent::of('inventory.level_changed', 'stock_level:'.$after->id, [
                'stock_item_id' => $after->item,
                'sku' => $after->sku,
                'location_id' => $after->location,
                'location_code' => $after->locationCode,
                'on_hand' => $view->onHand,
                'reserved' => $view->reserved,
                'safety_stock' => $view->safetyStock,
                'available' => $view->available,
                'version' => $view->version,
            ]));

            $this->events->dispatch(new StockLevelChanged($view, $change['kind'], $change['delta'], $change['reference']));

            $shortfall = $after->shortfall();

            if ($shortfall > 0 && $shortfall > $change['before']->shortfall()) {
                $this->events->dispatch(new StockShortfall($view, $shortfall));
            }
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return list<array{0: string, 1: string}> unique, in lock order
     */
    private static function sorted(array $pairs): array
    {
        $unique = [];

        foreach ($pairs as [$item, $location]) {
            $unique[LevelRow::key($item, $location)] = [$item, $location];
        }

        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    private static function assertQuantity(int $quantity): void
    {
        if ($quantity < 1) {
            throw new LogicException('A ledger quantity is at least 1.');
        }
    }

    private function assertScope(): void
    {
        if ($this->scopes === []) {
            throw new LogicException('Stock ledger changes run inside StockLedger::within().');
        }
    }

    private function assertTransaction(): void
    {
        if ($this->db()->transactionLevel() < 1) {
            throw new LogicException('The stock ledger works only inside a transaction on the Commerz connection.');
        }
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
