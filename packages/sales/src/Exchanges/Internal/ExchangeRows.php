<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Exchanges\Data\ClaimId;
use Commerz\Sales\Exchanges\Data\ClaimLineView;
use Commerz\Sales\Exchanges\Data\ExchangeId;
use Commerz\Sales\Exchanges\Data\ExchangeInboundLineView;
use Commerz\Sales\Exchanges\Data\ExchangeOutboundLineView;
use Commerz\Sales\Exchanges\Data\ListOrderClaims;
use Commerz\Sales\Exchanges\Data\ListOrderExchanges;
use Commerz\Sales\Exchanges\Data\OrderClaim;
use Commerz\Sales\Exchanges\Data\OrderExchange;
use Commerz\Sales\Exchanges\Enums\ClaimKind;
use Commerz\Sales\Exchanges\Enums\ClaimStatus;
use Commerz\Sales\Exchanges\Enums\ExchangeSettlement;
use Commerz\Sales\Exchanges\Enums\ExchangeStatus;
use Commerz\Sales\Exchanges\Exceptions\ClaimNotFound;
use Commerz\Sales\Exchanges\Exceptions\ExchangeNotFound;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Returns\Data\ReturnId;
use Commerz\Sales\Returns\Enums\ReturnReason;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

/**
 * The only writer of `exchanges`, `exchange_inbound_lines`, `exchange_outbound_lines`, `claims` and `claim_lines`.
 * Every write runs under the lock of the parent order.
 */
#[Internal]
final readonly class ExchangeRows
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /**
     * @param  list<ExchangeInboundLineView>  $inboundLines
     * @param  list<ExchangeOutboundLineView>  $outboundLines
     */
    public function insertExchange(
        ExchangeId $id,
        OrderId $order,
        ReturnId $return,
        ExchangeSettlement $settlement,
        ?ReturnReason $reason,
        ?string $note,
        Currency $currency,
        Money $returnTotal,
        Money $outboundSubtotal,
        Money $outboundTaxTotal,
        Money $outboundTotal,
        Money $netDelta,
        Money $amountDue,
        Money $refundDue,
        array $inboundLines,
        array $outboundLines,
    ): void {
        $now = PgTimestamp::now();

        $this->db()->table($this->tables->table('exchanges'))->insert([
            'id' => $id->value,
            'order_id' => $order->value,
            'return_id' => $return->value,
            'status' => ExchangeStatus::Requested->value,
            'settlement' => $settlement->value,
            'reason_code' => $reason?->value,
            'note' => $note,
            'currency' => $currency->code,
            'return_total_minor' => $returnTotal->amountMinor,
            'outbound_subtotal_minor' => $outboundSubtotal->amountMinor,
            'outbound_tax_total_minor' => $outboundTaxTotal->amountMinor,
            'outbound_total_minor' => $outboundTotal->amountMinor,
            'net_delta_minor' => $netDelta->amountMinor,
            'amount_due_minor' => $amountDue->amountMinor,
            'refund_due_minor' => $refundDue->amountMinor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db()->table($this->tables->table('exchange_inbound_lines'))->insert(array_map(
            static fn (ExchangeInboundLineView $line): array => [
                'exchange_id' => $id->value,
                'order_line_id' => $line->orderLineId->value,
                'sellable_ref' => (string) $line->sellable,
                'sku' => $line->sku,
                'name' => $line->name,
                'quantity' => $line->quantity,
                'currency' => $currency->code,
                'credit_total_minor' => $line->creditTotal->amountMinor,
            ],
            $inboundLines,
        ));

        $this->db()->table($this->tables->table('exchange_outbound_lines'))->insert(array_map(
            static fn (ExchangeOutboundLineView $line, int $index): array => [
                'id' => $line->id,
                'exchange_id' => $id->value,
                'position' => $index + 1,
                'sellable_ref' => (string) $line->sellable,
                'sku' => $line->sku,
                'name' => $line->name,
                'kind' => $line->kind->value,
                'requires_shipping' => $line->requiresShipping,
                'weight_grams' => $line->weightGrams,
                'quantity' => $line->quantity,
                'currency' => $currency->code,
                'unit_price_minor' => $line->unitPrice->amountMinor,
                'subtotal_minor' => $line->subtotal->amountMinor,
                'tax_total_minor' => $line->taxTotal->amountMinor,
                'total_minor' => $line->total->amountMinor,
            ],
            $outboundLines,
            array_keys($outboundLines),
        ));
    }

    public function markExchangeCompleted(ExchangeId $id, FulfillmentId $fulfillment): void
    {
        $now = PgTimestamp::now();

        $this->db()->table($this->tables->table('exchanges'))->where('id', $id->value)->update([
            'status' => ExchangeStatus::Completed->value,
            'fulfillment_id' => $fulfillment->value,
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function markExchangeCancelled(ExchangeId $id): void
    {
        $now = PgTimestamp::now();

        $this->db()->table($this->tables->table('exchanges'))->where('id', $id->value)->update([
            'status' => ExchangeStatus::Cancelled->value,
            'cancelled_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @throws ExchangeNotFound */
    public function orderOfExchange(ExchangeId $id): OrderId
    {
        $order = $this->db()->table($this->tables->table('exchanges'))->where('id', $id->value)->value('order_id');

        return new OrderId(is_string($order) ? $order : throw new ExchangeNotFound($id->value));
    }

    /** @throws ExchangeNotFound */
    public function getExchange(ExchangeId|string $id): OrderExchange
    {
        $id = $id instanceof ExchangeId ? $id->value : $id;
        $row = $this->db()->table($this->tables->table('exchanges'))->where('id', $id)->first();

        return $this->hydrateExchanges([$row ?? throw new ExchangeNotFound($id)])[0];
    }

    /** @throws ExchangeNotFound */
    public function lockExchange(ExchangeId $id): OrderExchange
    {
        $row = $this->db()->table($this->tables->table('exchanges'))->where('id', $id->value)->lockForUpdate()->first();

        return $this->hydrateExchanges([$row ?? throw new ExchangeNotFound($id->value)])[0];
    }

    /** @return Page<OrderExchange> */
    public function pageExchanges(ListOrderExchanges $query): Page
    {
        $builder = $this->db()->table($this->tables->table('exchanges'))
            ->select('*')
            ->selectRaw('cast(created_at as text) as created_position')
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
            $this->applyAfter($builder, $query->after);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return new Page(
            $this->hydrateExchanges($rows),
            $more && $last !== null ? Cursor::after(Rows::string($last, 'created_position').'|'.Rows::string($last, 'id')) : null,
        );
    }

    /**
     * @param  list<ClaimLineView>  $lines
     */
    public function insertClaim(
        ClaimId $id,
        OrderId $order,
        ClaimKind $kind,
        ?ReturnReason $reason,
        ?string $note,
        Currency $currency,
        array $lines,
    ): void {
        $now = PgTimestamp::now();

        $this->db()->table($this->tables->table('claims'))->insert([
            'id' => $id->value,
            'order_id' => $order->value,
            'kind' => $kind->value,
            'status' => ClaimStatus::Created->value,
            'reason_code' => $reason?->value,
            'note' => $note,
            'currency' => $currency->code,
            'subtotal_minor' => 0,
            'tax_total_minor' => 0,
            'invoice_total_minor' => 0,
            'amount_due_minor' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db()->table($this->tables->table('claim_lines'))->insert(array_map(
            static fn (ClaimLineView $line, int $index): array => [
                'id' => $line->id,
                'claim_id' => $id->value,
                'source_order_line_id' => $line->sourceOrderLineId?->value,
                'position' => $index + 1,
                'sellable_ref' => (string) $line->sellable,
                'sku' => $line->sku,
                'name' => $line->name,
                'kind' => $line->kind->value,
                'requires_shipping' => $line->requiresShipping,
                'weight_grams' => $line->weightGrams,
                'quantity' => $line->quantity,
                'currency' => $currency->code,
                'unit_price_minor' => 0,
                'subtotal_minor' => 0,
                'tax_total_minor' => 0,
                'total_minor' => 0,
            ],
            $lines,
            array_keys($lines),
        ));
    }

    public function markClaimFulfilled(ClaimId $id, FulfillmentId $fulfillment): void
    {
        $now = PgTimestamp::now();

        $this->db()->table($this->tables->table('claims'))->where('id', $id->value)->update([
            'status' => ClaimStatus::Fulfilled->value,
            'fulfillment_id' => $fulfillment->value,
            'fulfilled_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function markClaimCancelled(ClaimId $id): void
    {
        $now = PgTimestamp::now();

        $this->db()->table($this->tables->table('claims'))->where('id', $id->value)->update([
            'status' => ClaimStatus::Cancelled->value,
            'cancelled_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @throws ClaimNotFound */
    public function orderOfClaim(ClaimId $id): OrderId
    {
        $order = $this->db()->table($this->tables->table('claims'))->where('id', $id->value)->value('order_id');

        return new OrderId(is_string($order) ? $order : throw new ClaimNotFound($id->value));
    }

    /** @throws ClaimNotFound */
    public function getClaim(ClaimId|string $id): OrderClaim
    {
        $id = $id instanceof ClaimId ? $id->value : $id;
        $row = $this->db()->table($this->tables->table('claims'))->where('id', $id)->first();

        return $this->hydrateClaims([$row ?? throw new ClaimNotFound($id)])[0];
    }

    /** @throws ClaimNotFound */
    public function lockClaim(ClaimId $id): OrderClaim
    {
        $row = $this->db()->table($this->tables->table('claims'))->where('id', $id->value)->lockForUpdate()->first();

        return $this->hydrateClaims([$row ?? throw new ClaimNotFound($id->value)])[0];
    }

    /** @return Page<OrderClaim> */
    public function pageClaims(ListOrderClaims $query): Page
    {
        $builder = $this->db()->table($this->tables->table('claims'))
            ->select('*')
            ->selectRaw('cast(created_at as text) as created_position')
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
            $this->applyAfter($builder, $query->after);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return new Page(
            $this->hydrateClaims($rows),
            $more && $last !== null ? Cursor::after(Rows::string($last, 'created_position').'|'.Rows::string($last, 'id')) : null,
        );
    }

    /**
     * @param  list<object>  $rows
     * @return list<OrderExchange>
     */
    private function hydrateExchanges(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows);
        $inbound = [];
        $outbound = [];

        foreach ($this->db()->table($this->tables->table('exchange_inbound_lines'))
            ->whereIn('exchange_id', $ids)
            ->orderBy('order_line_id')
            ->get() as $line) {
            $currency = Currency::of(Rows::string($line, 'currency'));
            $inbound[Rows::string($line, 'exchange_id')][] = new ExchangeInboundLineView(
                new OrderLineId(Rows::string($line, 'order_line_id')),
                SellableRef::fromString(Rows::string($line, 'sellable_ref')),
                Rows::string($line, 'sku'),
                Rows::string($line, 'name'),
                Rows::int($line, 'quantity'),
                Money::ofMinor(Rows::int($line, 'credit_total_minor'), $currency),
            );
        }

        foreach ($this->db()->table($this->tables->table('exchange_outbound_lines'))
            ->whereIn('exchange_id', $ids)
            ->orderBy('position')
            ->get() as $line) {
            $currency = Currency::of(Rows::string($line, 'currency'));
            $outbound[Rows::string($line, 'exchange_id')][] = new ExchangeOutboundLineView(
                Rows::string($line, 'id'),
                SellableRef::fromString(Rows::string($line, 'sellable_ref')),
                Rows::string($line, 'sku'),
                Rows::string($line, 'name'),
                SellableKind::from(Rows::string($line, 'kind')),
                Rows::bool($line, 'requires_shipping'),
                Rows::nullableInt($line, 'weight_grams'),
                Rows::int($line, 'quantity'),
                Money::ofMinor(Rows::int($line, 'unit_price_minor'), $currency),
                Money::ofMinor(Rows::int($line, 'subtotal_minor'), $currency),
                Money::ofMinor(Rows::int($line, 'tax_total_minor'), $currency),
                Money::ofMinor(Rows::int($line, 'total_minor'), $currency),
            );
        }

        return array_map(static function (object $row) use ($inbound, $outbound): OrderExchange {
            $id = Rows::string($row, 'id');
            $currency = Currency::of(Rows::string($row, 'currency'));
            $fulfillment = Rows::nullableString($row, 'fulfillment_id');
            $reason = Rows::nullableString($row, 'reason_code');
            $completed = Rows::nullableString($row, 'completed_at');
            $cancelled = Rows::nullableString($row, 'cancelled_at');

            return new OrderExchange(
                new ExchangeId($id),
                new OrderId(Rows::string($row, 'order_id')),
                new ReturnId(Rows::string($row, 'return_id')),
                $fulfillment === null ? null : new FulfillmentId($fulfillment),
                ExchangeStatus::from(Rows::string($row, 'status')),
                ExchangeSettlement::from(Rows::string($row, 'settlement')),
                $reason === null ? null : ReturnReason::from($reason),
                Rows::nullableString($row, 'note'),
                $inbound[$id] ?? [],
                $outbound[$id] ?? [],
                Money::ofMinor(Rows::int($row, 'return_total_minor'), $currency),
                Money::ofMinor(Rows::int($row, 'outbound_subtotal_minor'), $currency),
                Money::ofMinor(Rows::int($row, 'outbound_tax_total_minor'), $currency),
                Money::ofMinor(Rows::int($row, 'outbound_total_minor'), $currency),
                Money::ofMinor(Rows::int($row, 'net_delta_minor'), $currency),
                Money::ofMinor(Rows::int($row, 'amount_due_minor'), $currency),
                Money::ofMinor(Rows::int($row, 'refund_due_minor'), $currency),
                $completed === null ? null : new DateTimeImmutable($completed),
                $cancelled === null ? null : new DateTimeImmutable($cancelled),
                Rows::date($row, 'created_at'),
            );
        }, $rows);
    }

    /**
     * @param  list<object>  $rows
     * @return list<OrderClaim>
     */
    private function hydrateClaims(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows);
        $lines = [];

        foreach ($this->db()->table($this->tables->table('claim_lines'))
            ->whereIn('claim_id', $ids)
            ->orderBy('position')
            ->get() as $line) {
            $currency = Currency::of(Rows::string($line, 'currency'));
            $sourceLine = Rows::nullableString($line, 'source_order_line_id');
            $zero = Money::zero($currency);
            $lines[Rows::string($line, 'claim_id')][] = new ClaimLineView(
                Rows::string($line, 'id'),
                $sourceLine === null ? null : new OrderLineId($sourceLine),
                SellableRef::fromString(Rows::string($line, 'sellable_ref')),
                Rows::string($line, 'sku'),
                Rows::string($line, 'name'),
                SellableKind::from(Rows::string($line, 'kind')),
                Rows::bool($line, 'requires_shipping'),
                Rows::nullableInt($line, 'weight_grams'),
                Rows::int($line, 'quantity'),
                $zero,
                $zero,
                $zero,
                $zero,
            );
        }

        return array_map(static function (object $row) use ($lines): OrderClaim {
            $id = Rows::string($row, 'id');
            $currency = Currency::of(Rows::string($row, 'currency'));
            $fulfillment = Rows::nullableString($row, 'fulfillment_id');
            $reason = Rows::nullableString($row, 'reason_code');
            $fulfilled = Rows::nullableString($row, 'fulfilled_at');
            $cancelled = Rows::nullableString($row, 'cancelled_at');
            $zero = Money::zero($currency);

            return new OrderClaim(
                new ClaimId($id),
                new OrderId(Rows::string($row, 'order_id')),
                ClaimKind::from(Rows::string($row, 'kind')),
                ClaimStatus::from(Rows::string($row, 'status')),
                $fulfillment === null ? null : new FulfillmentId($fulfillment),
                $reason === null ? null : ReturnReason::from($reason),
                Rows::nullableString($row, 'note'),
                $lines[$id] ?? [],
                $zero,
                $zero,
                $zero,
                $zero,
                $fulfilled === null ? null : new DateTimeImmutable($fulfilled),
                $cancelled === null ? null : new DateTimeImmutable($cancelled),
                Rows::date($row, 'created_at'),
            );
        }, $rows);
    }

    private function applyAfter(Builder $builder, Cursor $cursor): void
    {
        $parts = explode('|', $cursor->position());

        if (count($parts) !== 2
            || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?([+-]\d{2}(:\d{2}){0,2})?\z/', $parts[0]) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $parts[1]) !== 1) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        $db = $this->db();

        if ($db instanceof Connection && $db->getDriverName() === 'pgsql') {
            $builder->whereRaw('(created_at, id) < (?::timestamptz, ?::uuid)', $parts);
        } else {
            $builder->where(static function (Builder $sub) use ($parts): void {
                $sub->where('created_at', '<', $parts[0])
                    ->orWhere(static function (Builder $tie) use ($parts): void {
                        $tie->where('created_at', '=', $parts[0])->where('id', '<', $parts[1]);
                    });
            });
        }
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
