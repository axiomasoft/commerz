<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Returns\Data\ListReturns;
use Commerz\Sales\Returns\Data\ReturnId;
use Commerz\Sales\Returns\Data\ReturnLineView;
use Commerz\Sales\Returns\Data\ReturnView;
use Commerz\Sales\Returns\Enums\ReturnDisposition;
use Commerz\Sales\Returns\Enums\ReturnReason;
use Commerz\Sales\Returns\Enums\ReturnStatus;
use Commerz\Sales\Returns\Exceptions\ReturnNotFound;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;

/**
 * The only writer of `returns` and `return_lines`. Every write is made under the lock of the order the document
 * belongs to (sales-design#locks); the rows are read into views.
 */
#[Internal]
final readonly class ReturnRows
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /** @param  list<LineQuantity>  $lines */
    public function insert(ReturnId $id, OrderId $order, ?string $reason, array $lines, ?ReturnReason $returnReason = null): void
    {
        $now = PgTimestamp::now();

        $this->db()->table($this->tables->table('returns'))->insert([
            'id' => $id->value,
            'order_id' => $order->value,
            'status' => ReturnStatus::Requested->value,
            'reason' => $reason,
            'reason_code' => $returnReason?->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db()->table($this->tables->table('return_lines'))->insert(array_map(
            static fn (LineQuantity $line): array => [
                'return_id' => $id->value,
                'order_line_id' => $line->lineId->value,
                'quantity' => $line->quantity,
                'reason_code' => $returnReason?->value,
            ],
            $lines,
        ));
    }

    /**
     * Units held by the open (requested) returns of the order.
     *
     * @return array<string, int> by line id
     */
    public function openQuantities(OrderId $order): array
    {
        $open = [];

        $db = $this->db();
        $sumExpr = $db instanceof Connection && $db->getDriverName() === 'pgsql'
            ? 'l.order_line_id, sum(l.quantity)::bigint as held'
            : 'l.order_line_id, cast(sum(l.quantity) as integer) as held';

        foreach ($this->db()->table($this->tables->table('return_lines').' as l')
            ->join($this->tables->table('returns').' as r', 'r.id', '=', 'l.return_id')
            ->where('r.order_id', $order->value)
            ->where('r.status', ReturnStatus::Requested->value)
            ->groupBy('l.order_line_id')
            ->selectRaw($sumExpr)
            ->get() as $row) {
            $open[Rows::string($row, 'order_line_id')] = Rows::int($row, 'held');
        }

        return $open;
    }

    /** The channel of an order, read without a lock (it never changes). */
    public function channelOf(OrderId $order): ChannelId
    {
        $channel = $this->db()->table($this->tables->table('orders'))->where('id', $order->value)->value('channel_id');

        return new ChannelId(is_string($channel) ? $channel : throw new LogicException("Order '{$order->value}' has no channel."));
    }

    /** The order of a return, read without a lock (it never changes). @throws ReturnNotFound */
    public function orderOf(ReturnId $id): OrderId
    {
        $order = $this->db()->table($this->tables->table('returns'))->where('id', $id->value)->value('order_id');

        return new OrderId(is_string($order) ? $order : throw new ReturnNotFound($id->value));
    }

    /** @throws ReturnNotFound */
    public function get(ReturnId|string $id): ReturnView
    {
        $id = $id instanceof ReturnId ? $id->value : $id;
        $row = $this->db()->table($this->tables->table('returns'))->where('id', $id)->first();

        return $this->hydrate([$row ?? throw new ReturnNotFound($id)])[0];
    }

    /** The return `for update`; the caller holds the lock of its order. @throws ReturnNotFound */
    public function lock(ReturnId $id): ReturnView
    {
        $row = $this->db()->table($this->tables->table('returns'))->where('id', $id->value)->lockForUpdate()->first();

        return $this->hydrate([$row ?? throw new ReturnNotFound($id->value)])[0];
    }

    /**
     * @param  array<string, int>  $restocked  units put back on hand, by line id
     * @param  array<string, int>  $writtenOff  units written off as damaged, by line id
     * @param  array<string, ReturnDisposition>  $lineDispositions
     * @param  array<string, ReturnReason>  $lineReasons
     */
    public function markReceived(
        ReturnId $id,
        ?string $locationCode,
        array $restocked,
        array $writtenOff = [],
        ?ReturnDisposition $disposition = null,
        ?ReturnReason $reason = null,
        array $lineDispositions = [],
        array $lineReasons = [],
    ): void {
        $now = PgTimestamp::now();
        $touchedLines = array_values(array_unique([
            ...array_keys($restocked),
            ...array_keys($writtenOff),
            ...array_keys($lineDispositions),
            ...array_keys($lineReasons),
        ]));

        foreach ($touchedLines as $line) {
            $update = [];

            if (isset($restocked[$line])) {
                $update['restocked_quantity'] = $restocked[$line];
            }

            if (isset($writtenOff[$line])) {
                $update['written_off_quantity'] = $writtenOff[$line];
            }

            if (isset($lineDispositions[$line])) {
                $update['disposition'] = $lineDispositions[$line]->value;
            }

            if (isset($lineReasons[$line])) {
                $update['reason_code'] = $lineReasons[$line]->value;
            }

            if ($update !== []) {
                $this->db()->table($this->tables->table('return_lines'))
                    ->where('return_id', $id->value)
                    ->where('order_line_id', $line)
                    ->update($update);
            }
        }

        $headerUpdate = [
            'status' => ReturnStatus::Received->value,
            'location_code' => $locationCode,
            'disposition' => $disposition?->value,
            'received_at' => $now,
            'updated_at' => $now,
        ];

        if ($reason !== null) {
            $headerUpdate['reason_code'] = $reason->value;
        }

        $this->db()->table($this->tables->table('returns'))->where('id', $id->value)->update($headerUpdate);
    }

    public function markCancelled(ReturnId $id): void
    {
        $now = PgTimestamp::now();

        $this->db()->table($this->tables->table('returns'))->where('id', $id->value)->update([
            'status' => ReturnStatus::Cancelled->value,
            'cancelled_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return Page<ReturnView> newest first, by `(created_at, id)` */
    public function page(ListReturns $query): Page
    {
        $builder = $this->db()->table($this->tables->table('returns'))
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
     * @return list<ReturnView>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $lines = [];

        foreach ($this->db()->table($this->tables->table('return_lines'))
            ->whereIn('return_id', array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows))
            ->orderBy('order_line_id')
            ->get() as $line) {
            $lineDisposition = Rows::nullableString($line, 'disposition');
            $lineReason = Rows::nullableString($line, 'reason_code');

            $lines[Rows::string($line, 'return_id')][] = new ReturnLineView(
                new OrderLineId(Rows::string($line, 'order_line_id')),
                Rows::int($line, 'quantity'),
                Rows::int($line, 'restocked_quantity'),
                Rows::nullableInt($line, 'written_off_quantity') ?? 0,
                $lineDisposition === null ? null : ReturnDisposition::from($lineDisposition),
                $lineReason === null ? null : ReturnReason::from($lineReason),
            );
        }

        return array_map(static function (object $row) use ($lines): ReturnView {
            $received = Rows::nullableString($row, 'received_at');
            $disposition = Rows::nullableString($row, 'disposition');
            $reasonCode = Rows::nullableString($row, 'reason_code');

            return new ReturnView(
                new ReturnId(Rows::string($row, 'id')),
                new OrderId(Rows::string($row, 'order_id')),
                ReturnStatus::from(Rows::string($row, 'status')),
                $lines[Rows::string($row, 'id')] ?? [],
                Rows::nullableString($row, 'reason'),
                Rows::nullableString($row, 'location_code'),
                $received === null ? null : new DateTimeImmutable($received),
                Rows::date($row, 'created_at'),
                $disposition === null ? null : ReturnDisposition::from($disposition),
                $reasonCode === null ? null : ReturnReason::from($reasonCode),
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
