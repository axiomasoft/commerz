<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Ports\Delivery\Data\BookedParcel;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Shipping\Bookings\Contracts\Bookings;
use Commerz\Shipping\Bookings\Data\BookingId;
use Commerz\Shipping\Bookings\Data\BookingView;
use Commerz\Shipping\Bookings\Data\ListBookings;
use Commerz\Shipping\Bookings\Enums\BookingState;
use Commerz\Shipping\Bookings\Exceptions\BookingNotFound;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseBookings implements Bookings
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Clock $clock,
    ) {}

    public function find(string $reference): BookingView
    {
        $row = $this->query()->where('reference', $reference)->first() ?? throw new BookingNotFound($reference);
        $id = Rows::string($row, 'id');
        $parcelsByBooking = $this->parcelsFor([$id]);

        return self::view($row, $parcelsByBooking[$id] ?? []);
    }

    public function list(ListBookings $query): Page
    {
        $builder = $this->query()
            ->select('*')
            ->selectRaw('created_at::text as created_position')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($query->limit + 1);

        if ($query->state instanceof BookingState) {
            $builder->where('state', $query->state->value);
        }

        if ($query->after instanceof Cursor) {
            self::after($builder, $query->after);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        $parcelsByBooking = $this->parcelsFor(array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows));

        return new Page(
            array_map(
                static fn (object $row): BookingView => self::view($row, $parcelsByBooking[Rows::string($row, 'id')] ?? []),
                $rows,
            ),
            $more && $last !== null ? Cursor::after(Rows::string($last, 'created_position').'|'.Rows::string($last, 'id')) : null,
        );
    }

    public function erasePersonalData(array $references): int
    {
        if ($references === []) {
            return 0;
        }

        $now = PgTimestamp::now($this->clock);
        $ids = array_map(
            static fn (object $row): string => Rows::string($row, 'id'),
            $this->query()->whereIn('reference', $references)->get(['id'])->all(),
        );

        if ($ids === []) {
            return 0;
        }

        $this->db()->table($this->tables->table('shipping_booking_parcels'))
            ->whereIn('shipping_booking_id', $ids)
            ->update([
                'meta' => '{}',
                'updated_at' => $now,
            ]);

        return $this->query()
            ->whereIn('id', $ids)
            ->update([
                'result' => null,
                'error_message' => null,
                'updated_at' => $now,
            ]);
    }

    /**
     * @param  list<string>  $bookingIds
     * @return array<string, list<BookedParcel>>
     */
    private function parcelsFor(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }

        $grouped = [];

        foreach ($this->db()->table($this->tables->table('shipping_booking_parcels'))
            ->whereIn('shipping_booking_id', $bookingIds)
            ->orderBy('sequence')
            ->get()
            ->all() as $row) {
            $grouped[Rows::string($row, 'shipping_booking_id')][] = $row;
        }

        $result = [];

        foreach ($grouped as $bookingId => $rows) {
            $result[$bookingId] = BookingJournal::hydrateParcelRows($rows);
        }

        return $result;
    }

    /** The keyset is (created_at, id); the timestamp is PostgreSQL's own text of it, so no precision is lost. */
    private static function after(Builder $builder, Cursor $cursor): void
    {
        $parts = explode('|', $cursor->position());

        if (count($parts) !== 2
            || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2}){0,2}\z/', $parts[0]) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $parts[1]) !== 1) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        $builder->whereRaw('(created_at, id) > (?::timestamptz, ?::uuid)', $parts);
    }

    /** @param  list<BookedParcel>  $parcels */
    private static function view(object $row, array $parcels = []): BookingView
    {
        $booked = Rows::nullableString($row, 'booked_at');

        return new BookingView(
            new BookingId(Rows::string($row, 'id')),
            Rows::string($row, 'reference'),
            Rows::string($row, 'carrier'),
            Rows::string($row, 'method_code'),
            BookingState::from(Rows::string($row, 'state')),
            Rows::int($row, 'attempts'),
            Rows::nullableString($row, 'tracking'),
            Rows::nullableString($row, 'label_ref'),
            Rows::nullableString($row, 'result') === null ? [] : Rows::json($row, 'result'),
            Rows::nullableString($row, 'error_code'),
            Rows::nullableString($row, 'error_message'),
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
            $booked === null ? null : Rows::date($row, 'booked_at'),
            $parcels,
        );
    }

    private function query(): Builder
    {
        return $this->db()->table($this->tables->table('shipping_bookings'));
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
