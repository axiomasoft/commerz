<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
use Commerz\Inventory\Common\Internal\Clock;
use Commerz\Inventory\Common\Internal\Rows;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Reservations\Data\ReservationId;
use Commerz\Inventory\Reservations\Data\ReservationLineView;
use Commerz\Inventory\Reservations\Data\ReservationView;
use Commerz\Inventory\Reservations\Enums\ReservationState;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/**
 * SQL on `stock_reservations` and `stock_reservation_lines`. Callers own the lock order: a header (`for no key
 * update`, foreign headers only `skip locked`), then the levels through the ledger, then the lines
 * ({@see lockLines()}). Every expiry comparison uses the database clock.
 */
final readonly class ReservationStore
{
    private const string OUTSTANDING = 'ln.units > ln.consumed_units + ln.released_units + ln.expired_units';

    private const array COUNTERS = ['consumed_units', 'released_units', 'expired_units'];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
    ) {}

    /** Inserts the header of a new demand; null when the demand has one (a concurrent insert waits for its commit). */
    public function insert(string $demand, ChannelId $channel, ?DateTimeImmutable $expiresAt): ?string
    {
        $id = Uuid7::generate();
        $now = Clock::now();

        $row = $this->db()->selectOne(
            "insert into {$this->headerTable()} (id, demand, channel_id, state, generation, expires_at, created_at, updated_at)"
            ." values (?, ?, ?, 'active', 1, ?, ?, ?) on conflict (demand) do nothing returning id",
            [$id, $demand, $channel->value, self::timestamp($expiresAt), $now, $now],
            useReadPdo: false,
        );

        return is_object($row) ? $id : null;
    }

    /** @return object{id: string, channel_id: string, state: string, generation: int, expired: bool}|null */
    public function lock(string $demand): ?object
    {
        $row = $this->db()->selectOne(
            'select id, channel_id, state, generation, coalesce(expires_at <= now(), false) as expired'
            ." from {$this->headerTable()} where demand = ? for no key update",
            [$demand],
            useReadPdo: false,
        );

        /** @var object{id: string, channel_id: string, state: string, generation: int, expired: bool}|null */
        return is_object($row) ? $row : null;
    }

    public function reactivate(string $id, int $generation, ?DateTimeImmutable $expiresAt): void
    {
        $this->db()->update(
            "update {$this->headerTable()} set state = 'active', generation = ?, expires_at = ?, updated_at = ? where id = ?",
            [$generation, self::timestamp($expiresAt), Clock::now(), $id],
        );
    }

    /** Closes the active header once no line of its current generation is outstanding. */
    public function closeIfSettled(string $id): void
    {
        $this->db()->update(
            "update {$this->headerTable()} r set state = 'closed', updated_at = ? where r.id = ? and r.state = 'active'"
            ." and not exists (select 1 from {$this->lineTable()} ln where ln.reservation_id = r.id and ln.generation = r.generation and ".self::OUTSTANDING.')',
            [Clock::now(), $id],
        );
    }

    /**
     * Lines of the header's current generation, unlocked: under the header lock nobody else changes them.
     *
     * @return list<LineRow> in allocation order
     */
    public function lines(string $id, ?SellableRef $sellable = null, bool $outstanding = false): array
    {
        $bindings = [$id];
        $where = '';

        if ($sellable instanceof SellableRef) {
            $where .= ' and ln.sellable_ref = ?';
            $bindings[] = (string) $sellable;
        }

        if ($outstanding) {
            $where .= ' and '.self::OUTSTANDING;
        }

        return $this->lineRows(
            "select ln.*, l.code as location_code from {$this->lineTable()} ln"
            ." join {$this->headerTable()} r on r.id = ln.reservation_id and r.generation = ln.generation"
            ." join {$this->tables->table('stock_locations')} l on l.id = ln.location_id"
            ." where ln.reservation_id = ?{$where} order by ln.position, ln.id",
            $bindings,
        );
    }

    /**
     * Locks the lines `for no key update` in id order; call it after the lines' levels are locked.
     *
     * @param  list<string>  $ids
     * @return array<string, LineRow> by id
     */
    public function lockLines(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->lineRows(
            "select ln.*, l.code as location_code from {$this->lineTable()} ln"
            ." join {$this->tables->table('stock_locations')} l on l.id = ln.location_id"
            .' where ln.id in ('.self::placeholders($ids).') order by ln.id for no key update of ln',
            $ids,
        );

        $locked = [];

        foreach ($rows as $row) {
            $locked[$row->id] = $row;
        }

        return $locked;
    }

    /**
     * Active headers past their expiry, other than `$except`, with an outstanding line on one of the levels; takes
     * only headers nobody holds (`skip locked`), so it never waits.
     *
     * @param  list<string>  $levels  level ids
     * @return array<string, string> demand by header id
     */
    public function lockExpiredOn(array $levels, string $except): array
    {
        if ($levels === []) {
            return [];
        }

        $rows = $this->db()->select(
            "select r.id, r.demand from {$this->headerTable()} r"
            ." where r.state = 'active' and r.expires_at <= now() and r.id <> ?"
            ." and exists (select 1 from {$this->lineTable()} ln where ln.reservation_id = r.id and ln.generation = r.generation"
            .' and ln.stock_level_id in ('.self::placeholders($levels).') and '.self::OUTSTANDING.')'
            .' order by r.id for no key update of r skip locked',
            [$except, ...$levels],
            useReadPdo: false,
        );

        $headers = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $headers[Rows::string($row, 'id')] = Rows::string($row, 'demand');
            }
        }

        return $headers;
    }

    /**
     * Outstanding lines of the headers' current generations on the levels, locked `for no key update`; the caller
     * holds the headers and the levels.
     *
     * @param  list<string>  $headers
     * @param  list<string>  $levels  level ids
     * @return array<string, list<LineRow>> by header id
     */
    public function lockOutstandingOn(array $headers, array $levels): array
    {
        if ($headers === [] || $levels === []) {
            return [];
        }

        $rows = $this->db()->select(
            "select ln.*, l.code as location_code from {$this->lineTable()} ln"
            ." join {$this->headerTable()} r on r.id = ln.reservation_id and r.generation = ln.generation"
            ." join {$this->tables->table('stock_locations')} l on l.id = ln.location_id"
            .' where ln.reservation_id in ('.self::placeholders($headers).')'
            .' and ln.stock_level_id in ('.self::placeholders($levels).') and '.self::OUTSTANDING
            .' order by ln.id for no key update of ln',
            [...$headers, ...$levels],
            useReadPdo: false,
        );

        $lines = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $lines[Rows::string($row, 'reservation_id')][] = LineRow::from($row);
            }
        }

        return $lines;
    }

    /**
     * Ids of active headers past their expiry, earliest first; unlocked candidates for the sweeper.
     *
     * @return list<string>
     */
    public function expiredCandidates(int $limit): array
    {
        $ids = $this->db()->table($this->headerTable())
            ->where('state', ReservationState::Active->value)
            ->whereRaw('expires_at <= now()')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return array_values(array_filter($ids, is_string(...)));
    }

    /**
     * Takes the header for the sweeper when nobody holds it and it is still active and expired.
     *
     * @return string|null its demand
     */
    public function lockExpired(string $id): ?string
    {
        $row = $this->db()->selectOne(
            "select demand from {$this->headerTable()} where id = ? and state = 'active' and expires_at <= now()"
            .' for no key update skip locked',
            [$id],
            useReadPdo: false,
        );

        return is_object($row) ? Rows::string($row, 'demand') : null;
    }

    /**
     * @param  list<array{sellable: string, item: string, location: string, level: string, quantity_per_unit: int, units: int, position: int}>  $lines
     */
    public function insertLines(string $id, int $generation, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $now = Clock::now();
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [
                'id' => Uuid7::generate(),
                'reservation_id' => $id,
                'sellable_ref' => $line['sellable'],
                'stock_item_id' => $line['item'],
                'location_id' => $line['location'],
                'stock_level_id' => $line['level'],
                'generation' => $generation,
                'quantity_per_unit' => $line['quantity_per_unit'],
                'units' => $line['units'],
                'position' => $line['position'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->db()->table($this->lineTable())->insert($rows);
    }

    /** Adds units to one counter of a locked line; the row CHECK refuses counters beyond `units`. */
    public function count(LineRow $line, string $counter, int $units): void
    {
        if (! in_array($counter, self::COUNTERS, true) || $units < 1) {
            throw new LogicException("Cannot add {$units} to '{$counter}'.");
        }

        $this->db()->update(
            "update {$this->lineTable()} set {$counter} = {$counter} + ?, updated_at = ? where id = ?",
            [$units, Clock::now(), $line->id],
        );
    }

    /** The header and its current lines, read in one statement. */
    public function view(string $demand): ?ReservationView
    {
        $rows = $this->db()->select(
            'select r.id as reservation_id, r.demand, r.channel_id, r.state, r.generation, r.expires_at,'
            .' ln.sellable_ref, ln.stock_item_id, i.sku, ln.location_id, l.code as location_code, ln.quantity_per_unit,'
            .' ln.units, ln.consumed_units, ln.released_units, ln.expired_units'
            ." from {$this->headerTable()} r"
            ." left join {$this->lineTable()} ln on ln.reservation_id = r.id and ln.generation = r.generation"
            ." left join {$this->tables->table('stock_items')} i on i.id = ln.stock_item_id"
            ." left join {$this->tables->table('stock_locations')} l on l.id = ln.location_id"
            .' where r.demand = ? order by ln.position, i.sku, ln.id',
            [$demand],
            useReadPdo: $this->db()->transactionLevel() === 0,
        );

        $rows = array_values(array_filter($rows, is_object(...)));

        if ($rows === []) {
            return null;
        }

        $lines = [];

        foreach ($rows as $row) {
            if (Rows::nullableString($row, 'sellable_ref') === null) {
                continue;
            }

            $units = Rows::int($row, 'units');
            $consumed = Rows::int($row, 'consumed_units');
            $released = Rows::int($row, 'released_units');
            $expired = Rows::int($row, 'expired_units');

            $lines[] = new ReservationLineView(
                SellableRef::fromString(Rows::string($row, 'sellable_ref')),
                new StockItemId(Rows::string($row, 'stock_item_id')),
                Rows::string($row, 'sku'),
                new LocationId(Rows::string($row, 'location_id')),
                Rows::string($row, 'location_code'),
                Rows::int($row, 'quantity_per_unit'),
                $units,
                $consumed,
                $released,
                $expired,
                $units - $consumed - $released - $expired,
            );
        }

        $header = $rows[0];
        $expiresAt = Rows::nullableString($header, 'expires_at');

        return new ReservationView(
            new ReservationId(Rows::string($header, 'reservation_id')),
            Rows::string($header, 'demand'),
            new ChannelId(Rows::string($header, 'channel_id')),
            ReservationState::from(Rows::string($header, 'state')),
            Rows::int($header, 'generation'),
            $expiresAt === null ? null : new DateTimeImmutable($expiresAt),
            $lines,
        );
    }

    /**
     * @param  list<string>  $bindings
     * @return list<LineRow>
     */
    private function lineRows(string $sql, array $bindings): array
    {
        $lines = [];

        foreach ($this->db()->select($sql, $bindings, useReadPdo: false) as $row) {
            if (is_object($row)) {
                $lines[] = LineRow::from($row);
            }
        }

        return $lines;
    }

    private function headerTable(): string
    {
        return $this->tables->table('stock_reservations');
    }

    private function lineTable(): string
    {
        return $this->tables->table('stock_reservation_lines');
    }

    /** @param  list<string>  $values */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    private static function timestamp(?DateTimeInterface $at): ?string
    {
        return $at?->format('Y-m-d H:i:s.uP');
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
