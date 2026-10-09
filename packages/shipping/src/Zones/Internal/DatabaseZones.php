<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\TableNames;
use Commerz\Shipping\Common\Exceptions\DuplicateCode;
use Commerz\Shipping\Common\Internal\Clock;
use Commerz\Shipping\Common\Internal\Rows;
use Commerz\Shipping\Common\Internal\ShippingConfig;
use Commerz\Shipping\Zones\Contracts\Zones;
use Commerz\Shipping\Zones\Data\ArchiveZone;
use Commerz\Shipping\Zones\Data\CreateZone;
use Commerz\Shipping\Zones\Data\ListZones;
use Commerz\Shipping\Zones\Data\RestoreZone;
use Commerz\Shipping\Zones\Data\SetZoneMembers;
use Commerz\Shipping\Zones\Data\UpdateZone;
use Commerz\Shipping\Zones\Data\ZoneId;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Shipping\Zones\Data\ZoneView;
use Commerz\Shipping\Zones\Enums\ZoneStatus;
use Commerz\Shipping\Zones\Events\ZoneChanged;
use Commerz\Shipping\Zones\Exceptions\ZoneArchived;
use Commerz\Shipping\Zones\Exceptions\ZoneNotFound;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/** Lock order: the zone row `for update` first, then its member rows. */
final readonly class DatabaseZones implements Zones
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private ShippingConfig $shipping,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private ZoneRows $rows,
    ) {}

    public function create(CreateZone $command): ZoneView
    {
        return $this->transactions->run(function () use ($command): ZoneView {
            $this->assertMemberLimit($command->members);

            $db = $this->db();
            $id = Uuid7::generate();
            $now = Clock::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('shipping_zones'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => $command->name,
                    'status' => ZoneStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Shipping zone', $command->code);
            }

            $this->rows->insertMembers($db, $id, $command->members);
            $this->events->dispatch(new ZoneChanged(new ZoneId($id)));

            return $this->find(new ZoneId($id));
        });
    }

    public function update(UpdateZone $command): ZoneView
    {
        return $this->transactions->run(function () use ($command): ZoneView {
            $row = $this->lockActive($command->id);

            if ($command->name !== Rows::string($row, 'name')) {
                $this->touch($command->id, ['name' => $command->name]);
                $this->events->dispatch(new ZoneChanged($command->id));
            }

            return $this->find($command->id);
        });
    }

    public function setMembers(SetZoneMembers $command): ZoneView
    {
        return $this->transactions->run(function () use ($command): ZoneView {
            $this->lockActive($command->id);
            $this->assertMemberLimit($command->members);

            $db = $this->db();
            $current = $this->rows->membersOf($db, [$command->id->value])[$command->id->value] ?? [];

            if (self::keys($current) !== self::keys($command->members)) {
                $this->rows->deleteMembers($db, $command->id->value);
                $this->rows->insertMembers($db, $command->id->value, $command->members);
                $this->touch($command->id, []);
                $this->events->dispatch(new ZoneChanged($command->id));
            }

            return $this->find($command->id);
        });
    }

    public function archive(ArchiveZone $command): ZoneView
    {
        return $this->transactions->run(function () use ($command): ZoneView {
            $row = $this->lock($command->id);

            if (Rows::string($row, 'status') === ZoneStatus::Active->value) {
                $this->touch($command->id, ['status' => ZoneStatus::Archived->value]);
                $this->events->dispatch(new ZoneChanged($command->id));
            }

            return $this->find($command->id);
        });
    }

    public function restore(RestoreZone $command): ZoneView
    {
        return $this->transactions->run(function () use ($command): ZoneView {
            $row = $this->lock($command->id);

            if (Rows::string($row, 'status') === ZoneStatus::Archived->value) {
                $this->touch($command->id, ['status' => ZoneStatus::Active->value]);
                $this->events->dispatch(new ZoneChanged($command->id));
            }

            return $this->find($command->id);
        });
    }

    public function find(ZoneId $id): ZoneView
    {
        $db = $this->db();
        $row = $db->table($this->tables->table('shipping_zones'))->where('id', $id->value)->first();

        return $this->rows->view($row ?? throw new ZoneNotFound($id->value), $this->rows->membersOf($db, [$id->value]));
    }

    public function list(ListZones $query): Page
    {
        $db = $this->db();
        $builder = $db->table($this->tables->table('shipping_zones'))->orderBy('code')->limit($query->limit + 1);

        if ($query->status instanceof ZoneStatus) {
            $builder->where('status', $query->status->value);
        }

        if ($query->country !== null) {
            $builder->whereIn('id', $db->table($this->tables->table('shipping_zone_members'))
                ->select('shipping_zone_id')
                ->where('country', $query->country));
        }

        // The code is unique, so (code, id) ordering is the code alone.
        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $members = $this->rows->membersOf($db, array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows));
        $views = array_map(fn (object $row): ZoneView => $this->rows->view($row, $members), $rows);

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    /** @param  list<ZoneMember>  $members */
    private function assertMemberLimit(array $members): void
    {
        if (count($members) > $this->shipping->maxZoneMembers) {
            throw new InvalidCommand('members', "a zone has at most {$this->shipping->maxZoneMembers} members", 'shipping.too_many_zone_members');
        }
    }

    /**
     * @param  list<ZoneMember>  $members
     * @return list<string> sorted keys, to compare two member sets
     */
    private static function keys(array $members): array
    {
        $keys = array_map(
            static fn (ZoneMember $member): string => $member->country.'|'.($member->region ?? '').'|'.($member->postalPrefix ?? ''),
            $members,
        );
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @param  array<string, mixed>  $changes */
    private function touch(ZoneId $id, array $changes): void
    {
        $this->db()->table($this->tables->table('shipping_zones'))
            ->where('id', $id->value)
            ->update($changes + ['updated_at' => Clock::now()]);
    }

    private function lock(ZoneId $id): object
    {
        $row = $this->db()->table($this->tables->table('shipping_zones'))
            ->where('id', $id->value)
            ->lockForUpdate()
            ->first();

        return $row ?? throw new ZoneNotFound($id->value);
    }

    private function lockActive(ZoneId $id): object
    {
        $row = $this->lock($id);

        if (Rows::string($row, 'status') === ZoneStatus::Archived->value) {
            throw new ZoneArchived(Rows::string($row, 'code'));
        }

        return $row;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
