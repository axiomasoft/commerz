<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\IdempotencyKey;
use Commerz\Inventory\Common\Enums\UnlinkedSellables;
use Commerz\Inventory\Common\Internal\LevelRow;
use Commerz\Inventory\Common\Internal\Quantities;
use Commerz\Inventory\Common\Internal\Rows;
use Commerz\Inventory\Common\Internal\StockLedger;
use Commerz\Inventory\Configuration\InventoryOptions;
use Commerz\Inventory\Items\Enums\StockItemStatus;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Items\Exceptions\StockItemArchived;
use Commerz\Inventory\Locations\Enums\LocationStatus;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Commerz\Inventory\Stock\Exceptions\StockLinkMissing;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Fingerprint;
use Commerz\Ports\Stock\Contracts\StockReturns;
use Commerz\Ports\Stock\Data\RestockLine;
use Commerz\Ports\Stock\Data\RestockStock;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * Restocks every tracked or backorder component of the sellables' current links (`units × quantity_per_unit`,
 * movement `return`) into the given location or the channel's first active source. Untracked components are skipped;
 * a sellable without links is skipped under `unlinked_sellables = unlimited` and fails otherwise. Once per reference
 * (`inventory.restock` receipt): a repeated reference changes nothing, a different payload under it conflicts.
 */
#[Internal]
final readonly class DatabaseStockReturns implements StockReturns
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private OperationReceipts $receipts,
        private StockLedger $ledger,
        private StockEntities $entities,
        private Channels $channels,
        private InventoryOptions $inventory,
    ) {}

    public function restock(RestockStock $command): void
    {
        $this->channels->find($command->channel);

        $fingerprint = Fingerprint::of([
            'channel' => $command->channel->value,
            'location' => $command->locationCode,
            'lines' => array_map(static fn (RestockLine $line): array => [(string) $line->sellable, $line->quantity], $command->lines),
        ]);

        $this->receipts->once(
            'inventory.restock',
            IdempotencyKey::of($command->reference),
            $fingerprint,
            // The receipt runs the work inside its transaction (OperationReceipts joins or opens one).
            fn (): bool => $this->ledger->within(fn (): bool => $this->apply($command)),
            static fn (bool $restocked): array => ['restocked' => $restocked],
            static fn (array $stored): bool => ($stored['restocked'] ?? false) === true,
        );
    }

    /** @return bool whether any unit went back on hand */
    private function apply(RestockStock $command): bool
    {
        $units = $this->componentUnits($command->lines);

        if ($units === []) {
            return false;
        }

        $items = $this->entities->items(array_keys($units));

        foreach ($items as $id => $row) {
            // The policy read under the lock decides: an item that became untracked meanwhile keeps no levels.
            if (Rows::string($row, 'policy') === StockPolicy::Untracked->value) {
                unset($units[$id]);
            } elseif (Rows::string($row, 'status') === StockItemStatus::Archived->value) {
                throw new StockItemArchived(Rows::string($row, 'sku'));
            }
        }

        if ($units === []) {
            return false;
        }

        $location = $this->location($command);

        if (Rows::string($location, 'status') === LocationStatus::Archived->value) {
            throw new LocationArchived(Rows::string($location, 'code'));
        }

        $locationId = Rows::string($location, 'id');
        $pairs = array_map(static fn (string $item): array => [$item, $locationId], array_keys($units));
        $this->ledger->ensureLevels($pairs);
        $levels = $this->ledger->lock($pairs);

        foreach ($units as $item => $quantity) {
            $level = $levels[LevelRow::key($item, $locationId)]
                ?? throw new RuntimeException("The level of {$item} at {$locationId} vanished under its locks.");

            $this->ledger->move($level, $quantity, MovementKind::Return, $command->reference);
        }

        return true;
    }

    /**
     * Units to put back per stock item of the non-untracked links; validates every line before anything is written.
     *
     * @param  list<RestockLine>  $lines
     * @return array<string, int> by stock item id
     */
    private function componentUnits(array $lines): array
    {
        $refs = array_map(static fn (RestockLine $line): string => (string) $line->sellable, $lines);
        $links = [];

        $rows = $this->db()->table($this->tables->table('stock_item_links').' as l')
            ->join($this->tables->table('stock_items').' as i', 'i.id', '=', 'l.stock_item_id')
            ->whereIn('l.sellable_ref', $refs)
            ->orderBy('l.stock_item_id')
            ->get(['l.sellable_ref', 'l.stock_item_id', 'l.quantity_per_unit', 'i.policy']);

        foreach ($rows as $row) {
            $links[Rows::string($row, 'sellable_ref')][] = $row;
        }

        $units = [];

        foreach ($lines as $line) {
            $ref = (string) $line->sellable;

            if (! isset($links[$ref])) {
                if ($this->inventory->unlinkedSellables === UnlinkedSellables::Unlimited) {
                    continue;
                }

                throw new StockLinkMissing($ref);
            }

            foreach ($links[$ref] as $link) {
                if (Rows::string($link, 'policy') === StockPolicy::Untracked->value) {
                    continue;
                }

                $item = Rows::string($link, 'stock_item_id');
                $quantity = Quantities::mul($line->quantity, Rows::int($link, 'quantity_per_unit'));
                $total = $quantity === null ? null : Quantities::add($units[$item] ?? 0, $quantity);
                $units[$item] = $total ?? throw new InvalidCommand('quantity', 'overflows int64', 'inventory.invalid_quantity');
            }
        }

        ksort($units, SORT_STRING);

        return $units;
    }

    private function location(RestockStock $command): object
    {
        if ($command->locationCode !== null) {
            return $this->entities->locationByCode($command->locationCode);
        }

        $first = $this->db()->table($this->tables->table('stock_sources').' as s')
            ->join($this->tables->table('stock_locations').' as l', 'l.id', '=', 's.location_id')
            ->where('s.channel_id', $command->channel->value)
            ->where('l.status', LocationStatus::Active->value)
            ->orderBy('s.priority')
            ->orderBy('l.code')
            ->value('l.id');

        if (! is_string($first)) {
            throw new InvalidCommand('locationCode', 'is required: the channel has no active stock source', 'inventory.no_stock_source');
        }

        return $this->entities->location($first);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
