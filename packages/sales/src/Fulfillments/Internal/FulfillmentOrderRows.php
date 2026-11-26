<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrder;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrderId;
use Commerz\Sales\Fulfillments\Data\ListFulfillmentOrders;
use Commerz\Sales\Fulfillments\Enums\FulfillmentOrderStatus;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentOrderNotFound;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use JsonException;
use LogicException;
use Packman\Attributes\Internal;

/**
 * The sole writer of `fulfillment_orders` and `fulfillment_order_lines`. Every write executes under the lock
 * of the owning order.
 */
#[Internal]
final readonly class FulfillmentOrderRows
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    public function insert(FulfillmentOrder $order): void
    {
        $this->db()->table($this->tables->table('fulfillment_orders'))->insert([
            'id' => $order->id->value,
            'order_id' => $order->order->value,
            'location_code' => $order->locationCode,
            'status' => $order->status->value,
            'constraints' => self::encodeConstraints($order->constraints),
            'fulfillment_id' => $order->fulfillment?->value,
            'assigned_at' => PgTimestamp::format($order->assignedAt),
            'fulfilled_at' => $order->fulfilledAt === null ? null : PgTimestamp::format($order->fulfilledAt),
            'cancelled_at' => $order->cancelledAt === null ? null : PgTimestamp::format($order->cancelledAt),
            'created_at' => PgTimestamp::format($order->createdAt),
            'updated_at' => PgTimestamp::format($order->updatedAt),
        ]);

        $this->db()->table($this->tables->table('fulfillment_order_lines'))->insert(array_map(
            static fn (LineQuantity $line): array => [
                'fulfillment_order_id' => $order->id->value,
                'order_line_id' => $line->lineId->value,
                'quantity' => $line->quantity,
            ],
            $order->lines,
        ));
    }

    public function save(FulfillmentOrder $order): void
    {
        $this->db()->table($this->tables->table('fulfillment_orders'))
            ->where('id', $order->id->value)
            ->update([
                'location_code' => $order->locationCode,
                'status' => $order->status->value,
                'fulfillment_id' => $order->fulfillment?->value,
                'assigned_at' => PgTimestamp::format($order->assignedAt),
                'fulfilled_at' => $order->fulfilledAt === null ? null : PgTimestamp::format($order->fulfilledAt),
                'cancelled_at' => $order->cancelledAt === null ? null : PgTimestamp::format($order->cancelledAt),
                'updated_at' => PgTimestamp::format($order->updatedAt),
            ]);
    }

    /** @throws FulfillmentOrderNotFound */
    public function orderOf(FulfillmentOrderId $id): OrderId
    {
        $order = $this->db()->table($this->tables->table('fulfillment_orders'))->where('id', $id->value)->value('order_id');

        return new OrderId(is_string($order) ? $order : throw new FulfillmentOrderNotFound($id->value));
    }

    /** @throws FulfillmentOrderNotFound */
    public function get(FulfillmentOrderId|string $id): FulfillmentOrder
    {
        $id = $id instanceof FulfillmentOrderId ? $id->value : $id;
        $row = $this->db()->table($this->tables->table('fulfillment_orders'))->where('id', $id)->first();

        return $this->hydrate([$row ?? throw new FulfillmentOrderNotFound($id)])[0];
    }

    /** @throws FulfillmentOrderNotFound */
    public function lock(FulfillmentOrderId $id): FulfillmentOrder
    {
        $row = $this->db()->table($this->tables->table('fulfillment_orders'))->where('id', $id->value)->lockForUpdate()->first();

        return $this->hydrate([$row ?? throw new FulfillmentOrderNotFound($id->value)])[0];
    }

    /**
     * Sum of quantities already assigned to active (non-cancelled) fulfillment orders for `$order`, by order line id.
     *
     * @return array<string, int>
     */
    public function assignedQuantities(OrderId $order): array
    {
        $ordersTable = $this->tables->table('fulfillment_orders');
        $linesTable = $this->tables->table('fulfillment_order_lines');

        $rows = $this->db()->table($linesTable)
            ->join($ordersTable, "{$ordersTable}.id", '=', "{$linesTable}.fulfillment_order_id")
            ->where("{$ordersTable}.order_id", $order->value)
            ->where("{$ordersTable}.status", '!=', FulfillmentOrderStatus::Cancelled->value)
            ->select("{$linesTable}.order_line_id")
            ->selectRaw('sum(quantity) as total_quantity')
            ->groupBy("{$linesTable}.order_line_id")
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[Rows::string($row, 'order_line_id')] = Rows::int($row, 'total_quantity');
        }

        return $totals;
    }

    /** @return list<FulfillmentOrder> */
    public function forOrder(OrderId $order): array
    {
        $rows = $this->db()->table($this->tables->table('fulfillment_orders'))
            ->where('order_id', $order->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        return $this->hydrate(array_values($rows));
    }

    /** @return Page<FulfillmentOrder> newest first, by `(created_at, id)` */
    public function page(ListFulfillmentOrders $query): Page
    {
        $builder = $this->db()->table($this->tables->table('fulfillment_orders'))
            ->select('*')
            ->selectRaw('created_at::text as created_position')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($query->limit + 1);

        if ($query->order !== null) {
            $builder->where('order_id', $query->order->value);
        }

        if ($query->locationCode !== null) {
            $builder->where('location_code', $query->locationCode);
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
     * @param  list<object>  $rows
     * @return list<FulfillmentOrder>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        /** @var array<string, list<LineQuantity>> $linesByOrder */
        $linesByOrder = [];

        foreach ($this->db()->table($this->tables->table('fulfillment_order_lines'))
            ->whereIn('fulfillment_order_id', array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows))
            ->orderBy('order_line_id')
            ->get() as $line) {
            $linesByOrder[Rows::string($line, 'fulfillment_order_id')][] = new LineQuantity(
                new OrderLineId(Rows::string($line, 'order_line_id')),
                Rows::int($line, 'quantity'),
            );
        }

        return array_map(static function (object $row) use ($linesByOrder): FulfillmentOrder {
            $id = Rows::string($row, 'id');
            $lines = $linesByOrder[$id] ?? [];

            if ($lines === []) {
                throw new LogicException("Stored fulfillment order '{$id}' has no lines.");
            }

            $fulfillmentId = Rows::nullableString($row, 'fulfillment_id');
            $fulfilledAt = Rows::nullableString($row, 'fulfilled_at');
            $cancelledAt = Rows::nullableString($row, 'cancelled_at');

            return new FulfillmentOrder(
                id: new FulfillmentOrderId($id),
                order: new OrderId(Rows::string($row, 'order_id')),
                locationCode: Rows::string($row, 'location_code'),
                status: FulfillmentOrderStatus::from(Rows::string($row, 'status')),
                lines: $lines,
                constraints: self::decodeConstraints(Rows::string($row, 'constraints')),
                fulfillment: $fulfillmentId === null ? null : new FulfillmentId($fulfillmentId),
                assignedAt: Rows::date($row, 'assigned_at'),
                fulfilledAt: $fulfilledAt === null ? null : new DateTimeImmutable($fulfilledAt),
                cancelledAt: $cancelledAt === null ? null : new DateTimeImmutable($cancelledAt),
                createdAt: Rows::date($row, 'created_at'),
                updatedAt: Rows::date($row, 'updated_at'),
            );
        }, $rows);
    }

    private static function encodeConstraints(FulfillmentConstraints $constraints): string
    {
        return json_encode($constraints->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decodeConstraints(string $json): FulfillmentConstraints
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Stored fulfillment order constraints are not valid JSON.', previous: $e);
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new LogicException('Stored fulfillment order constraints are not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return FulfillmentConstraints::fromArray($decoded);
    }

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
