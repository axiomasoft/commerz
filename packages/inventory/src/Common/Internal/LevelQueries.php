<?php

declare(strict_types=1);

namespace Commerz\Inventory\Common\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Exceptions\StockItemNotFound;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Inventory\Movements\Data\ListMovements;
use Commerz\Inventory\Movements\Data\MovementView;
use Commerz\Inventory\Stock\Data\ListStockLevels;
use Commerz\Inventory\Stock\Data\StockLevelView;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\JoinClause;

/**
 * Reads of `stock_levels` and `stock_movements` outside a ledger operation; the only such reader besides the ledger
 * itself. Each read is one statement, so a page never mixes two snapshots.
 */
final readonly class LevelQueries
{
    private const array LEVEL_COLUMNS = [
        'i.id as stock_item_id', 'i.sku', 'l.id as location_id', 'l.code as location_code',
        'lv.on_hand', 'lv.reserved', 'lv.safety_stock', 'lv.version', 'lv.updated_at',
    ];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
    ) {}

    /**
     * Locks the item's levels in a stable order, then tells whether any holds or reserves stock.
     * Call it inside the transaction that already holds the item row, so a reserve cannot slip in.
     */
    public function lockedItemNonEmpty(string $item): bool
    {
        return $this->nonEmpty('stock_item_id', $item, 'location_id');
    }

    /** The location's counterpart of {@see lockedItemNonEmpty()}. */
    public function lockedLocationNonEmpty(string $location): bool
    {
        return $this->nonEmpty('location_id', $location, 'stock_item_id');
    }

    /** The level of the pair; a pair never stocked reads as zeros. */
    public function level(StockItemId $item, LocationId $location): StockLevelView
    {
        $row = $this->db()->table($this->tables->table('stock_items').' as i')
            ->crossJoin($this->tables->table('stock_locations').' as l')
            ->leftJoin($this->tables->table('stock_levels').' as lv', static fn (JoinClause $join): JoinClause => $join
                ->on('lv.stock_item_id', '=', 'i.id')
                ->on('lv.location_id', '=', 'l.id'))
            ->where('i.id', $item->value)
            ->where('l.id', $location->value)
            ->first(self::LEVEL_COLUMNS);

        if ($row === null) {
            $exists = $this->db()->table($this->tables->table('stock_items'))->where('id', $item->value)->exists();

            throw $exists ? new LocationNotFound($location->value) : new StockItemNotFound($item->value);
        }

        return self::levelView($row);
    }

    /** @return Page<StockLevelView> */
    public function levels(ListStockLevels $query): Page
    {
        $builder = $this->db()->table($this->tables->table('stock_levels').' as lv')
            ->join($this->tables->table('stock_items').' as i', 'i.id', '=', 'lv.stock_item_id')
            ->join($this->tables->table('stock_locations').' as l', 'l.id', '=', 'lv.location_id')
            ->orderBy('lv.id')
            ->limit($query->first + 1)
            ->select([...self::LEVEL_COLUMNS, 'lv.id']);

        if ($query->item instanceof StockItemId) {
            $builder->where('lv.stock_item_id', $query->item->value);
        }

        if ($query->location instanceof LocationId) {
            $builder->where('lv.location_id', $query->location->value);
        }

        if ($query->after instanceof Cursor) {
            $position = $query->after->position();

            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $position) !== 1) {
                throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
            }

            $builder->where('lv.id', '>', $position);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $page = array_slice($rows, 0, $query->first);
        $views = array_map(self::levelView(...), $page);

        return new Page($views, $more && $page !== [] ? Cursor::after(Rows::string($page[array_key_last($page)], 'id')) : null);
    }

    /** @return Page<MovementView> */
    public function movements(ListMovements $query): Page
    {
        $builder = $this->db()->table($this->tables->table('stock_movements').' as m')
            ->join($this->tables->table('stock_locations').' as l', 'l.id', '=', 'm.location_id')
            ->orderBy('m.id')
            ->limit($query->first + 1)
            ->select(['m.*', 'l.code as location_code']);

        if ($query->item instanceof StockItemId) {
            $builder->where('m.stock_item_id', $query->item->value);
        }

        if ($query->location instanceof LocationId) {
            $builder->where('m.location_id', $query->location->value);
        }

        if ($query->reference !== null) {
            $builder->where('m.reference', $query->reference);
        }

        if ($query->after instanceof Cursor) {
            $position = $query->after->position();
            $id = filter_var($position, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (! is_int($id) || (string) $id !== $position) {
                throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
            }

            $builder->where('m.id', '>', $id);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = array_map(self::movementView(...), array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after((string) $views[array_key_last($views)]->id) : null);
    }

    private function nonEmpty(string $column, string $id, string $order): bool
    {
        $rows = $this->db()->table($this->tables->table('stock_levels'))
            ->where($column, $id)
            ->orderBy($order)
            ->lock('for no key update')
            ->get(['on_hand', 'reserved']);

        foreach ($rows as $row) {
            if (Rows::int($row, 'on_hand') !== 0 || Rows::int($row, 'reserved') !== 0) {
                return true;
            }
        }

        return false;
    }

    private static function levelView(object $row): StockLevelView
    {
        $updatedAt = Rows::nullableString($row, 'updated_at');

        return new StockLevelView(
            new StockItemId(Rows::string($row, 'stock_item_id')),
            Rows::string($row, 'sku'),
            new LocationId(Rows::string($row, 'location_id')),
            Rows::string($row, 'location_code'),
            Rows::nullableInt($row, 'on_hand') ?? 0,
            Rows::nullableInt($row, 'reserved') ?? 0,
            Rows::nullableInt($row, 'safety_stock') ?? 0,
            Rows::nullableInt($row, 'version') ?? 0,
            $updatedAt === null ? null : new DateTimeImmutable($updatedAt),
        );
    }

    private static function movementView(object $row): MovementView
    {
        $actor = Rows::nullableString($row, 'actor_ref');

        return new MovementView(
            Rows::int($row, 'id'),
            new StockItemId(Rows::string($row, 'stock_item_id')),
            new LocationId(Rows::string($row, 'location_id')),
            Rows::string($row, 'location_code'),
            MovementKind::from(Rows::string($row, 'kind')),
            Rows::int($row, 'delta'),
            Rows::int($row, 'balance_after'),
            Rows::nullableString($row, 'reference'),
            Rows::nullableString($row, 'reason'),
            $actor === null ? null : ActorRef::fromString($actor),
            Rows::date($row, 'occurred_at'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
