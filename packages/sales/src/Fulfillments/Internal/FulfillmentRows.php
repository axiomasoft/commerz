<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Internal\Clock;
use Commerz\Sales\Common\Internal\Rows;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Fulfillments\Data\FulfillmentView;
use Commerz\Sales\Fulfillments\Data\ListFulfillments;
use Commerz\Sales\Fulfillments\Enums\FulfillmentStatus;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotFound;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

/**
 * The only writer of `fulfillments` and `fulfillment_lines`. Every write is made under the lock of the order the
 * document belongs to (sales-design#locks); the rows are read into views.
 */
#[Internal]
final readonly class FulfillmentRows
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /** @param  list<LineQuantity>  $lines */
    public function insert(FulfillmentId $id, OrderId $order, ?string $locationCode, array $lines): void
    {
        $now = Clock::now();

        $this->db()->table($this->tables->table('fulfillments'))->insert([
            'id' => $id->value,
            'order_id' => $order->value,
            'status' => FulfillmentStatus::Created->value,
            'location_code' => $locationCode,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db()->table($this->tables->table('fulfillment_lines'))->insert(array_map(
            static fn (LineQuantity $line): array => ['fulfillment_id' => $id->value, 'order_line_id' => $line->lineId->value, 'quantity' => $line->quantity],
            $lines,
        ));
    }

    /** The order of a fulfillment, read without a lock (it never changes). @throws FulfillmentNotFound */
    public function orderOf(FulfillmentId $id): OrderId
    {
        $order = $this->db()->table($this->tables->table('fulfillments'))->where('id', $id->value)->value('order_id');

        return new OrderId(is_string($order) ? $order : throw new FulfillmentNotFound($id->value));
    }

    /** @throws FulfillmentNotFound */
    public function get(FulfillmentId|string $id): FulfillmentView
    {
        $id = $id instanceof FulfillmentId ? $id->value : $id;
        $row = $this->db()->table($this->tables->table('fulfillments'))->where('id', $id)->first();

        return $this->hydrate([$row ?? throw new FulfillmentNotFound($id)])[0];
    }

    /** The fulfillment `for update`; the caller holds the lock of its order. @throws FulfillmentNotFound */
    public function lock(FulfillmentId $id): FulfillmentView
    {
        $row = $this->db()->table($this->tables->table('fulfillments'))->where('id', $id->value)->lockForUpdate()->first();

        return $this->hydrate([$row ?? throw new FulfillmentNotFound($id->value)])[0];
    }

    /** A durable intent: a manual shipment cannot turn an in-flight carrier call into an unobservable booking. */
    public function bookingRequested(FulfillmentId $id): bool
    {
        $row = $this->db()->table($this->tables->table('fulfillments'))->where('id', $id->value)->first(['booking_requested']);

        return $row !== null && Rows::bool($row, 'booking_requested');
    }

    /** The caller holds the order and fulfillment locks; the shipping package owns network deduplication and leases. */
    public function requestBooking(FulfillmentId $id): void
    {
        $this->db()->table($this->tables->table('fulfillments'))->where('id', $id->value)->update(['booking_requested' => true]);
    }

    /** A manual shipment that matched the carrier's answer keeps its original times and gains the carrier label. */
    public function attachBooking(FulfillmentId $id, BookedShipment $booking, string $methodCode): void
    {
        $this->db()->table($this->tables->table('fulfillments'))->where('id', $id->value)->update([
            'method_code' => $methodCode,
            'label_ref' => $booking->labelRef,
            'updated_at' => Clock::now(),
        ]);
    }

    public function markShipped(FulfillmentId $id, ?string $carrier, ?string $tracking, ?string $labelRef, ?string $methodCode): void
    {
        $now = Clock::now();

        $this->db()->table($this->tables->table('fulfillments'))->where('id', $id->value)->update([
            'status' => FulfillmentStatus::Shipped->value,
            'carrier' => $carrier,
            'tracking' => $tracking,
            'label_ref' => $labelRef,
            'method_code' => $methodCode,
            'shipped_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function markDelivered(FulfillmentId $id): void
    {
        $now = Clock::now();

        $this->db()->table($this->tables->table('fulfillments'))->where('id', $id->value)->update([
            'status' => FulfillmentStatus::Delivered->value,
            'delivered_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return Page<FulfillmentView> newest first, by `(created_at, id)` */
    public function page(ListFulfillments $query): Page
    {
        $builder = $this->db()->table($this->tables->table('fulfillments'))
            ->select('*')
            ->selectRaw('created_at::text as created_position')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($query->limit + 1);

        if ($query->order !== null) {
            $builder->where('order_id', $query->order->value);
        }

        if ($query->status !== null) {
            $builder->where('status', $query->status->value);
        }

        if ($query->after instanceof Cursor) {
            self::after($builder, $query->after);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return new Page(
            $this->hydrate($rows),
            $more && $last !== null ? Cursor::after(Rows::string($last, 'created_position').'|'.Rows::string($last, 'id')) : null,
        );
    }

    /**
     * Builds the views of the rows, reading all their lines in one query.
     *
     * @param  list<object>  $rows
     * @return list<FulfillmentView>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $lines = [];

        foreach ($this->db()->table($this->tables->table('fulfillment_lines'))
            ->whereIn('fulfillment_id', array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows))
            ->orderBy('order_line_id')
            ->get() as $line) {
            $lines[Rows::string($line, 'fulfillment_id')][] = new LineQuantity(new OrderLineId(Rows::string($line, 'order_line_id')), Rows::int($line, 'quantity'));
        }

        return array_map(static function (object $row) use ($lines): FulfillmentView {
            $date = static function (string $column) use ($row): ?DateTimeImmutable {
                $value = Rows::nullableString($row, $column);

                return $value === null ? null : new DateTimeImmutable($value);
            };

            return new FulfillmentView(
                new FulfillmentId(Rows::string($row, 'id')),
                new OrderId(Rows::string($row, 'order_id')),
                FulfillmentStatus::from(Rows::string($row, 'status')),
                $lines[Rows::string($row, 'id')] ?? [],
                Rows::nullableString($row, 'location_code'),
                Rows::nullableString($row, 'carrier'),
                Rows::nullableString($row, 'method_code'),
                Rows::nullableString($row, 'tracking'),
                Rows::nullableString($row, 'label_ref'),
                $date('shipped_at'),
                $date('delivered_at'),
                Rows::date($row, 'created_at'),
            );
        }, $rows);
    }

    /** The keyset is (created_at, id), newest first, in PostgreSQL's own text of the timestamp. */
    private static function after(Builder $builder, Cursor $cursor): void
    {
        $parts = explode('|', $cursor->position());

        if (count($parts) !== 2
            || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2}){0,2}\z/', $parts[0]) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $parts[1]) !== 1) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        $builder->whereRaw('(created_at, id) < (?::timestamptz, ?::uuid)', $parts);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
