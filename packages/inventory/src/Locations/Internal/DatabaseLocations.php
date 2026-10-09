<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Identity\Uuid7;
use Commerz\Inventory\Common\Exceptions\DuplicateCode;
use Commerz\Inventory\Common\Internal\Clock;
use Commerz\Inventory\Common\Internal\LevelQueries;
use Commerz\Inventory\Common\Internal\Rows;
use Commerz\Inventory\Locations\Contracts\Locations;
use Commerz\Inventory\Locations\Data\ArchiveLocation;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Data\ListLocations;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Locations\Data\LocationView;
use Commerz\Inventory\Locations\Data\RestoreLocation;
use Commerz\Inventory\Locations\Data\UpdateLocation;
use Commerz\Inventory\Locations\Enums\LocationKind;
use Commerz\Inventory\Locations\Enums\LocationStatus;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotEmpty;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Archiving takes the location row (`for no key update`), then its levels in a stable order, and only then reads
 * them: a reserve filters on the location status before it locks levels and rechecks it in the same query, so it
 * cannot add stock to a location that is being archived.
 */
final readonly class DatabaseLocations implements Locations
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
        private Transactions $transactions,
        private LevelQueries $levels,
    ) {}

    public function create(CreateLocation $command): LocationView
    {
        return $this->transactions->run(function () use ($command): LocationView {
            $db = $this->db();
            $id = Uuid7::generate();
            $now = Clock::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('stock_locations'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => $command->name,
                    'kind' => $command->kind->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Location', $command->code);
            }

            return $this->find(new LocationId($id));
        });
    }

    public function update(UpdateLocation $command): LocationView
    {
        return $this->transactions->run(function () use ($command): LocationView {
            $row = $this->lock($command->location);

            if (Rows::string($row, 'status') === LocationStatus::Archived->value) {
                throw new LocationArchived(Rows::string($row, 'code'));
            }

            $changes = [];

            if ($command->name !== null && $command->name !== Rows::string($row, 'name')) {
                $changes['name'] = $command->name;
            }

            if ($command->kind instanceof LocationKind && $command->kind->value !== Rows::string($row, 'kind')) {
                $changes['kind'] = $command->kind->value;
            }

            if ($changes !== []) {
                $this->db()->table($this->tables->table('stock_locations'))
                    ->where('id', $command->location->value)
                    ->update($changes + ['updated_at' => Clock::now()]);
            }

            return $this->find($command->location);
        });
    }

    public function archive(ArchiveLocation $command): LocationView
    {
        return $this->transactions->run(function () use ($command): LocationView {
            $row = $this->lock($command->location);

            if (Rows::string($row, 'status') === LocationStatus::Archived->value) {
                return $this->find($command->location);
            }

            $open = $this->db()->table($this->tables->table('stock_transfers'))
                ->whereIn('state', ['draft', 'in_transit'])
                ->where(static fn (Builder $query): Builder => $query
                    ->where('from_location_id', $command->location->value)
                    ->orWhere('to_location_id', $command->location->value))
                ->exists();

            if ($open || $this->levels->lockedLocationNonEmpty($command->location->value)) {
                throw new LocationNotEmpty(Rows::string($row, 'code'));
            }

            $this->setStatus($command->location, LocationStatus::Archived);

            return $this->find($command->location);
        });
    }

    public function restore(RestoreLocation $command): LocationView
    {
        return $this->transactions->run(function () use ($command): LocationView {
            $row = $this->lock($command->location);

            if (Rows::string($row, 'status') === LocationStatus::Archived->value) {
                $this->setStatus($command->location, LocationStatus::Active);
            }

            return $this->find($command->location);
        });
    }

    public function find(LocationId $id): LocationView
    {
        $row = $this->db()->table($this->tables->table('stock_locations'))->where('id', $id->value)->first();

        return self::view($row ?? throw new LocationNotFound($id->value));
    }

    public function findByCode(string $code): LocationView
    {
        $row = $this->db()->table($this->tables->table('stock_locations'))->where('code', $code)->first();

        return self::view($row ?? throw new LocationNotFound($code));
    }

    public function list(ListLocations $query): Page
    {
        $builder = $this->db()->table($this->tables->table('stock_locations'))->orderBy('code')->limit($query->first + 1);

        if ($query->status instanceof LocationStatus) {
            $builder->where('status', $query->status->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = array_map(self::view(...), array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    private function setStatus(LocationId $id, LocationStatus $status): void
    {
        $this->db()->table($this->tables->table('stock_locations'))
            ->where('id', $id->value)
            ->update(['status' => $status->value, 'updated_at' => Clock::now()]);
    }

    private function lock(LocationId $id): object
    {
        $row = $this->db()->table($this->tables->table('stock_locations'))
            ->where('id', $id->value)
            ->lock('for no key update')
            ->first();

        return $row ?? throw new LocationNotFound($id->value);
    }

    private static function view(object $row): LocationView
    {
        return new LocationView(
            new LocationId(Rows::string($row, 'id')),
            Rows::string($row, 'code'),
            Rows::string($row, 'name'),
            LocationKind::from(Rows::string($row, 'kind')),
            LocationStatus::from(Rows::string($row, 'status')),
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
