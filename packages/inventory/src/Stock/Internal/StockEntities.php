<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Items\Exceptions\StockItemNotFound;
use Commerz\Inventory\Items\Exceptions\UntrackedStockItem;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/**
 * Holds the items and the location of a stock change `for share` — items first, ordered by id, then the location —
 * so archiving them (`for no key update`, then their levels) waits until the change commits and then sees it.
 */
#[Internal]
final readonly class StockEntities
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /**
     * @param  list<string>  $items
     *
     * @throws StockItemNotFound|UntrackedStockItem|LocationNotFound
     */
    public function lockTracked(array $items, string $location): void
    {
        foreach ($this->items($items) as $row) {
            if (Rows::string($row, 'policy') === StockPolicy::Untracked->value) {
                throw UntrackedStockItem::keepsNoLevels(Rows::string($row, 'sku'));
            }
        }

        $this->location($location);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, object> rows by id
     *
     * @throws StockItemNotFound
     */
    public function items(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        $rows = [];

        foreach ($this->db()->table($this->tables->table('stock_items'))->whereIn('id', $ids)->orderBy('id')->lock('for share')->get() as $row) {
            $rows[Rows::string($row, 'id')] = $row;
        }

        foreach ($ids as $id) {
            if (! isset($rows[$id])) {
                throw new StockItemNotFound($id);
            }
        }

        return $rows;
    }

    /** @throws LocationNotFound */
    public function location(string $id): object
    {
        $row = $this->db()->table($this->tables->table('stock_locations'))->where('id', $id)->lock('for share')->first();

        return $row ?? throw new LocationNotFound($id);
    }

    /** @throws LocationNotFound */
    public function locationByCode(string $code): object
    {
        $row = $this->db()->table($this->tables->table('stock_locations'))->where('code', $code)->lock('for share')->first();

        return $row ?? throw new LocationNotFound($code);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
