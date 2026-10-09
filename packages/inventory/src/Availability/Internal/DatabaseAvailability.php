<?php

declare(strict_types=1);

namespace Commerz\Inventory\Availability\Internal;

use Commerz\Inventory\Allocation\Contracts\AllocationStrategy;
use Commerz\Inventory\Allocation\Data\SourceStock;
use Commerz\Inventory\Common\Internal\InventoryConfig;
use Commerz\Inventory\Common\Internal\LevelQueries;
use Commerz\Inventory\Common\Internal\Rows;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Reservations\Internal\DemandResolver;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Data\AvailabilityAnswer;
use Commerz\Ports\Stock\Data\AvailabilityLine;
use Commerz\Ports\Stock\Data\CheckAvailability;
use Commerz\Ports\Stock\Data\LineAvailability;
use Commerz\Stores\Contracts\Channels;

/**
 * Answers how many units of each sellable the channel could reserve now: the configured allocation strategy's
 * capacity over the channel's active sources, read without locks in one snapshot. Expired reservations the sweeper
 * has not closed yet do not count as held. Kits give the units their scarcest component allows.
 */
final readonly class DatabaseAvailability implements StockAvailability
{
    public function __construct(
        private LevelQueries $queries,
        private DemandResolver $demand,
        private Contributions $contributions,
        private InventoryConfig $inventory,
        private Channels $channels,
    ) {}

    public function check(CheckAvailability $query): AvailabilityAnswer
    {
        $this->channels->assertActive($query->channel);

        $rows = $this->queries->sourceStock(
            $query->channel->value,
            array_map(static fn (AvailabilityLine $line): string => (string) $line->sellable, $query->lines),
        );

        $links = [];
        $sources = [];

        foreach ($rows as $row) {
            $item = Rows::string($row, 'stock_item_id');
            $links[Rows::string($row, 'sellable_ref').'|'.$item] = $row;
            $location = Rows::nullableString($row, 'location_id');

            if ($location === null) {
                continue;
            }

            $sources[$location] ??= ['code' => Rows::string($row, 'location_code'), 'priority' => Rows::int($row, 'priority'), 'free' => []];
            $free = Rows::nullableInt($row, 'free');

            if ($free !== null) {
                $sources[$location]['free'][$item] = $free;
            }
        }

        uasort($sources, static fn (array $a, array $b): int => [$a['priority'], $a['code']] <=> [$b['priority'], $b['code']]);
        $stock = [];

        foreach ($sources as $location => $source) {
            $stock[] = new SourceStock(new LocationId($location), $source['code'], $source['priority'], $source['free']);
        }

        $strategy = $this->contributions->get(AllocationStrategy::KIND, $this->inventory->allocationStrategy, AllocationStrategy::class);
        $answers = [];

        foreach ($this->demand->build($query->lines, array_values($links)) as $line) {
            $available = match (true) {
                $line->unavailable => 0,
                $line->components === null => null,
                // Without a source nothing can be held, whatever a strategy says of backorder items.
                $stock === [] => 0,
                default => $strategy->capacity($line->need(), $stock),
            };

            $answers[] = new LineAvailability($line->sellable, $line->units, $available);
        }

        return new AvailabilityAnswer($answers);
    }
}
