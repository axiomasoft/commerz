<?php

declare(strict_types=1);

namespace Commerz\Inventory\Common\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/** Reads of `stock_levels` outside a ledger operation; the only such reader besides the ledger itself. */
final readonly class LevelQueries
{
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

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
