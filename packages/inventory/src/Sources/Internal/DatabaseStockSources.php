<?php

declare(strict_types=1);

namespace Commerz\Inventory\Sources\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Locations\Enums\LocationStatus;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Inventory\Sources\Contracts\StockSources;
use Commerz\Inventory\Sources\Data\AssignSource;
use Commerz\Inventory\Sources\Data\StockSourceView;
use Commerz\Inventory\Sources\Data\UnassignSource;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ChannelId;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/** The location is held `for share` while it is assigned, so archiving it (`for no key update`) cannot slip past. */
#[Internal]
final readonly class DatabaseStockSources implements StockSources
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Channels $channels,
    ) {}

    public function assign(AssignSource $command): StockSourceView
    {
        $this->channels->find($command->channel);

        return $this->transactions->run(function () use ($command): StockSourceView {
            $db = $this->db();
            $location = $db->table($this->tables->table('stock_locations'))
                ->where('id', $command->location->value)
                ->lock('for share')
                ->first() ?? throw new LocationNotFound($command->location->value);

            if (Rows::string($location, 'status') === LocationStatus::Archived->value) {
                throw new LocationArchived(Rows::string($location, 'code'));
            }

            $now = PgTimestamp::now();
            $db->table($this->tables->table('stock_sources'))->upsert([[
                'id' => Uuid7::generate(),
                'channel_id' => $command->channel->value,
                'location_id' => $command->location->value,
                'priority' => $command->priority,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['channel_id', 'location_id'], ['priority', 'updated_at']);

            return new StockSourceView(
                $command->channel,
                $command->location,
                Rows::string($location, 'code'),
                $command->priority,
            );
        });
    }

    public function unassign(UnassignSource $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $this->db()->table($this->tables->table('stock_sources'))
                ->where('channel_id', $command->channel->value)
                ->where('location_id', $command->location->value)
                ->delete();
        });
    }

    public function list(ChannelId $channel): array
    {
        $this->channels->find($channel);

        $rows = $this->db()->table($this->tables->table('stock_sources').' as s')
            ->join($this->tables->table('stock_locations').' as l', 'l.id', '=', 's.location_id')
            ->where('s.channel_id', $channel->value)
            ->orderBy('s.priority')
            ->orderBy('l.code')
            ->get(['s.channel_id', 's.location_id', 'l.code', 's.priority'])
            ->all();

        return array_values(array_map(static fn (object $row): StockSourceView => new StockSourceView(
            new ChannelId(Rows::string($row, 'channel_id')),
            new LocationId(Rows::string($row, 'location_id')),
            Rows::string($row, 'code'),
            Rows::int($row, 'priority'),
        ), $rows));
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
