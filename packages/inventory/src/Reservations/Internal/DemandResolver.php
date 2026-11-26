<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Inventory\Allocation\Data\NeedComponent;
use Commerz\Inventory\Common\Enums\UnlinkedSellables;
use Commerz\Inventory\Configuration\InventoryOptions;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Items\Enums\StockItemStatus;
use Commerz\Inventory\Items\Enums\StockPolicy;
use Commerz\Inventory\Locations\Enums\LocationStatus;
use Commerz\Ports\Stock\Data\AvailabilityLine;
use Commerz\Ports\Stock\Data\ReserveLine;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/**
 * Turns requested sellables into stock needs through their current links: untracked items drop out, a sellable
 * left with none is unlimited, an archived item makes it unavailable, and an unlinked one follows
 * `commerz-inventory.unlinked_sellables`. Links are read unlocked (a new link applies to later reservations); the
 * items and the source locations are held `for share`, so archiving or re-policing them waits for the reservation.
 */
#[Internal]
final readonly class DemandResolver
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private InventoryOptions $inventory,
    ) {}

    /**
     * @param  list<ReserveLine>  $lines
     * @return list<DemandLine> in the order of the lines
     */
    public function lock(array $lines): array
    {
        $refs = array_map(static fn (ReserveLine $line): string => (string) $line->sellable, $lines);

        $links = $this->db()->table($this->tables->table('stock_item_links'))
            ->whereIn('sellable_ref', $refs)
            ->orderBy('stock_item_id')
            ->get(['sellable_ref', 'stock_item_id', 'quantity_per_unit'])
            ->all();

        $ids = array_values(array_unique(array_map(static fn (object $link): string => Rows::string($link, 'stock_item_id'), $links)));
        sort($ids, SORT_STRING);
        $items = [];

        if ($ids !== []) {
            $rows = $this->db()->table($this->tables->table('stock_items'))
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lock('for share')
                ->get(['id', 'policy', 'status']);

            foreach ($rows as $row) {
                $items[Rows::string($row, 'id')] = $row;
            }
        }

        $rows = [];

        foreach ($links as $link) {
            $item = $items[Rows::string($link, 'stock_item_id')] ?? null;

            // A link whose item vanished cannot happen (foreign key); skipping keeps the shape total.
            if ($item !== null) {
                $rows[] = (object) [
                    'sellable_ref' => Rows::string($link, 'sellable_ref'),
                    'stock_item_id' => Rows::string($item, 'id'),
                    'quantity_per_unit' => Rows::int($link, 'quantity_per_unit'),
                    'policy' => Rows::string($item, 'policy'),
                    'item_status' => Rows::string($item, 'status'),
                ];
            }
        }

        return $this->build($lines, $rows);
    }

    /**
     * @param  list<ReserveLine|AvailabilityLine>  $lines
     * @param  list<object>  $rows  `sellable_ref, stock_item_id, quantity_per_unit, policy, item_status` per link
     * @return list<DemandLine> in the order of the lines
     */
    public function build(array $lines, array $rows): array
    {
        $links = [];

        foreach ($rows as $row) {
            $links[Rows::string($row, 'sellable_ref')][Rows::string($row, 'stock_item_id')] = $row;
        }

        $resolved = [];

        foreach ($lines as $line) {
            $ref = (string) $line->sellable;

            if (! isset($links[$ref])) {
                $unlimited = $this->inventory->unlinkedSellables === UnlinkedSellables::Unlimited;
                $resolved[] = new DemandLine($line->sellable, $line->quantity, $unlimited ? null : [], ! $unlimited);

                continue;
            }

            $components = [];
            $unavailable = false;

            foreach ($links[$ref] as $item => $link) {
                $policy = StockPolicy::from(Rows::string($link, 'policy'));
                $unavailable = $unavailable || Rows::string($link, 'item_status') === StockItemStatus::Archived->value;

                if ($policy === StockPolicy::Untracked) {
                    continue;
                }

                $components[] = new NeedComponent(new StockItemId($item), Rows::int($link, 'quantity_per_unit'), $policy === StockPolicy::Backorder);
            }

            $resolved[] = new DemandLine($line->sellable, $line->quantity, $components === [] && ! $unavailable ? null : $components, $unavailable);
        }

        return $resolved;
    }

    /**
     * The channel's active source locations held `for share`, in allocation order (priority, then code).
     *
     * @return list<array{id: string, code: string, priority: int}>
     */
    public function lockSources(ChannelId $channel): array
    {
        $rows = $this->db()->select(
            "select l.id, l.code, l.status, s.priority from {$this->tables->table('stock_sources')} s"
            ." join {$this->tables->table('stock_locations')} l on l.id = s.location_id"
            .' where s.channel_id = ? order by l.id for share of l',
            [$channel->value],
            useReadPdo: false,
        );

        $sources = [];

        foreach ($rows as $row) {
            if (is_object($row) && Rows::string($row, 'status') === LocationStatus::Active->value) {
                $sources[] = ['id' => Rows::string($row, 'id'), 'code' => Rows::string($row, 'code'), 'priority' => Rows::int($row, 'priority')];
            }
        }

        usort($sources, static fn (array $a, array $b): int => [$a['priority'], $a['code']] <=> [$b['priority'], $b['code']]);

        return $sources;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
