<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\ActorRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Sales\OrderChanges\Data\CompensationOutcome;
use Commerz\Sales\OrderChanges\Data\OrderChangeAction;
use Commerz\Sales\OrderChanges\Data\OrderChangeActionId;
use Commerz\Sales\OrderChanges\Data\OrderChangeActionView;
use Commerz\Sales\OrderChanges\Data\OrderChangeId;
use Commerz\Sales\OrderChanges\Data\OrderChangePreview;
use Commerz\Sales\OrderChanges\Data\OrderChangeView;
use Commerz\Sales\OrderChanges\Enums\OrderChangeActionType;
use Commerz\Sales\OrderChanges\Enums\OrderChangeStatus;
use Commerz\Sales\OrderChanges\Exceptions\OrderChangeNotFound;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/** Reads and writes `cmz_order_changes` and `cmz_order_change_actions`. */
#[Internal]
final readonly class OrderChangeRows
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /** @throws OrderChangeNotFound */
    public function get(string $id): OrderChangeView
    {
        $row = $this->db()->table($this->tables->table('order_changes'))->where('id', $id)->first();

        return $this->hydrate([$row ?? throw new OrderChangeNotFound($id)])[0];
    }

    /** @throws OrderChangeNotFound */
    public function lock(string $id): OrderChangeView
    {
        $row = $this->db()->table($this->tables->table('order_changes'))->where('id', $id)->lockForUpdate()->first();

        return $this->hydrate([$row ?? throw new OrderChangeNotFound($id)])[0];
    }

    /** @return list<OrderChangeView> newest first */
    public function listForOrder(OrderId $order): array
    {
        $rows = $this->db()->table($this->tables->table('order_changes'))
            ->where('order_id', $order->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->all();

        return $this->hydrate(array_values($rows));
    }

    public function insert(
        OrderChangeId $id,
        OrderId $orderId,
        int $baseOrderVersion,
        Currency $currency,
        ?string $description,
        ?ActorRef $actor,
        DateTimeImmutable $at,
    ): void {
        $now = PgTimestamp::format($at);

        $this->db()->table($this->tables->table('order_changes'))->insert([
            'id' => $id->value,
            'order_id' => $orderId->value,
            'status' => OrderChangeStatus::Draft->value,
            'base_order_version' => $baseOrderVersion,
            'currency' => $currency->code,
            'amount_delta_minor' => 0,
            'tax_delta_minor' => 0,
            'description' => $description,
            'compensation_result' => '{}',
            'created_by_ref' => $actor === null ? null : (string) $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function appendAction(
        OrderChangeId $changeId,
        OrderChangeActionId $actionId,
        int $position,
        OrderChangeAction $action,
        DateTimeImmutable $at,
    ): void {
        $now = PgTimestamp::format($at);

        $this->db()->table($this->tables->table('order_change_actions'))->insert([
            'id' => $actionId->value,
            'order_change_id' => $changeId->value,
            'position' => $position,
            'type' => $action->type()->value,
            'payload' => OrderChangeCodec::encodeObject($action->toPayload()),
            'created_at' => $now,
        ]);

        $this->db()->table($this->tables->table('order_changes'))
            ->where('id', $changeId->value)
            ->update(['updated_at' => $now]);
    }

    public function markConfirmed(
        OrderChangeId $changeId,
        int $confirmedOrderVersion,
        OrderChangePreview $preview,
        CompensationOutcome $compensation,
        ?ActorRef $actor,
        DateTimeImmutable $at,
    ): void {
        $now = PgTimestamp::format($at);

        $this->db()->table($this->tables->table('order_changes'))
            ->where('id', $changeId->value)
            ->update([
                'status' => OrderChangeStatus::Confirmed->value,
                'confirmed_order_version' => $confirmedOrderVersion,
                'amount_delta_minor' => $preview->amountDelta->amountMinor,
                'tax_delta_minor' => $preview->taxDelta->amountMinor,
                'compensation_strategy' => $compensation->strategy->value,
                'compensation_result' => OrderChangeCodec::compensationToJson($compensation),
                'excerpt_before' => OrderChangeCodec::excerptToJson($preview->excerptBefore),
                'excerpt_after' => OrderChangeCodec::excerptToJson($preview->excerptAfter),
                'confirmed_by_ref' => $actor === null ? null : (string) $actor,
                'confirmed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function recordCompensation(
        OrderChangeId $changeId,
        CompensationOutcome $compensation,
        DateTimeImmutable $at,
    ): void {
        $this->db()->table($this->tables->table('order_changes'))
            ->where('id', $changeId->value)
            ->update([
                'compensation_strategy' => $compensation->strategy->value,
                'compensation_result' => OrderChangeCodec::compensationToJson($compensation),
                'updated_at' => PgTimestamp::format($at),
            ]);
    }

    public function markCancelled(OrderChangeId $changeId, DateTimeImmutable $at): void
    {
        $now = PgTimestamp::format($at);

        $this->db()->table($this->tables->table('order_changes'))
            ->where('id', $changeId->value)
            ->update([
                'status' => OrderChangeStatus::Cancelled->value,
                'cancelled_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * @param  list<object>  $rows
     * @return list<OrderChangeView>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $actionsByChange = [];
        $ids = array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows);

        foreach ($this->db()->table($this->tables->table('order_change_actions'))
            ->whereIn('order_change_id', $ids)
            ->orderBy('position')
            ->get() as $action) {
            $actionsByChange[Rows::string($action, 'order_change_id')][] = new OrderChangeActionView(
                id: new OrderChangeActionId(Rows::string($action, 'id')),
                position: Rows::int($action, 'position'),
                type: OrderChangeActionType::from(Rows::string($action, 'type')),
                payload: OrderChangeCodec::decodeObject(Rows::string($action, 'payload')),
                createdAt: Rows::date($action, 'created_at'),
            );
        }

        return array_map(static function (object $row) use ($actionsByChange): OrderChangeView {
            $id = Rows::string($row, 'id');
            $currency = Currency::of(Rows::string($row, 'currency'));
            $createdBy = Rows::nullableString($row, 'created_by_ref');
            $confirmedBy = Rows::nullableString($row, 'confirmed_by_ref');
            $confirmedAt = Rows::nullableString($row, 'confirmed_at');
            $cancelledAt = Rows::nullableString($row, 'cancelled_at');

            return new OrderChangeView(
                id: new OrderChangeId($id),
                orderId: new OrderId(Rows::string($row, 'order_id')),
                status: OrderChangeStatus::from(Rows::string($row, 'status')),
                baseOrderVersion: Rows::int($row, 'base_order_version'),
                confirmedOrderVersion: Rows::nullableInt($row, 'confirmed_order_version'),
                currency: $currency,
                amountDelta: Money::ofMinor(Rows::int($row, 'amount_delta_minor'), $currency),
                taxDelta: Money::ofMinor(Rows::int($row, 'tax_delta_minor'), $currency),
                description: Rows::nullableString($row, 'description'),
                actions: $actionsByChange[$id] ?? [],
                compensation: OrderChangeCodec::compensationFromRow(
                    Rows::nullableString($row, 'compensation_strategy'),
                    Rows::string($row, 'compensation_result'),
                    $currency,
                ),
                createdBy: $createdBy === null ? null : ActorRef::fromString($createdBy),
                confirmedBy: $confirmedBy === null ? null : ActorRef::fromString($confirmedBy),
                createdAt: Rows::date($row, 'created_at'),
                confirmedAt: $confirmedAt === null ? null : new DateTimeImmutable($confirmedAt),
                cancelledAt: $cancelledAt === null ? null : new DateTimeImmutable($cancelledAt),
            );
        }, $rows);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
