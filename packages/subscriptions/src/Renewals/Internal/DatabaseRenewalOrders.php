<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Renewals\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Schema\TableNames;
use Commerz\Subscriptions\Internal\SubscriptionRowMapper;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContractId;
use Commerz\Subscriptions\Renewals\Contracts\RenewalOrderCreator;
use Commerz\Subscriptions\Renewals\Contracts\RenewalOrders;
use Commerz\Subscriptions\Renewals\Data\PlaceRenewalOrder;
use Commerz\Subscriptions\Renewals\Data\RenewalOrderId;
use Commerz\Subscriptions\Renewals\Data\RenewalOrderResult;
use Commerz\Subscriptions\Renewals\Data\RenewalOrderView;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Packman\Attributes\Internal;

/**
 * Default database and Outbox implementation of {@see RenewalOrderCreator} and {@see RenewalOrders}.
 */
#[Internal]
final readonly class DatabaseRenewalOrders implements RenewalOrderCreator, RenewalOrders
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $commerzOptions,
        private TableNames $tables,
        private Outbox $outbox,
    ) {}

    #[Override]
    public function placeRenewalOrder(PlaceRenewalOrder $command): RenewalOrderResult
    {
        if ($command->sellingPlan->requiresSavedPaymentMethod
            && ($command->paymentMethodRef === null || trim($command->paymentMethodRef) === '')) {
            return RenewalOrderResult::failed('missing_saved_payment_method');
        }

        $db = $this->db();
        $table = $this->tables->table('subscription_renewal_orders');

        $existing = $db->table($table)
            ->where('idempotency_key', $command->idempotencyKey)
            ->first();

        if ($existing !== null) {
            $view = SubscriptionRowMapper::renewalOrder($existing);

            return RenewalOrderResult::succeeded($view->orderRef, $view->dueNowAmount);
        }

        $id = RenewalOrderId::generate();
        $orderRef = 'order:'.$id->value;
        $totals = $command->quote->totals;
        $currency = $command->context->currency->code;
        $createdAtTs = PgTimestamp::format($command->occurredAt);

        $linesPayload = array_map(
            static fn (QuotedLine $line): array => [
                'line_id' => $line->lineId,
                'sellable_ref' => (string) $line->sellable->ref,
                'sku' => $line->sellable->sku,
                'name' => $line->sellable->name,
                'kind' => $line->sellable->kind->value,
                'requires_shipping' => $line->sellable->requiresShipping,
                'quantity' => $line->quantity,
                'unit_price_minor' => $line->unitPrice->amountMinor,
                'subtotal_minor' => $line->subtotal->amountMinor,
                'total_minor' => $line->total->amountMinor,
                'selling_plan' => $line->sellingPlan?->toArray(),
            ],
            $command->quote->lines,
        );

        $db->table($table)->insert([
            'id' => $id->value,
            'order_ref' => $orderRef,
            'contract_id' => $command->contractId->value,
            'cycle_id' => $command->cycleId->value,
            'cycle_number' => $command->cycleNumber,
            'channel_id' => $command->context->channel->value,
            'customer_ref' => (string) $command->customer,
            'payment_method_ref' => $command->paymentMethodRef,
            'currency' => $currency,
            'subtotal_minor' => $totals->subtotal->amountMinor,
            'discount_total_minor' => $totals->discountTotal->amountMinor,
            'delivery_total_minor' => $totals->deliveryTotal->amountMinor,
            'tax_total_minor' => $totals->taxTotal->amountMinor,
            'total_minor' => $totals->total->amountMinor,
            'due_now_minor' => $command->quote->dueNowAmount->amountMinor,
            'idempotency_key' => $command->idempotencyKey,
            'lines_snapshot' => json_encode($linesPayload, JSON_THROW_ON_ERROR),
            'created_at' => $createdAtTs,
        ]);

        $this->outbox->record(IntegrationEvent::of(
            'subscriptions.renewal_order_created',
            $orderRef,
            [
                'renewal_order_id' => $id->value,
                'order_ref' => $orderRef,
                'subscription_contract_id' => $command->contractId->value,
                'billing_cycle_id' => $command->cycleId->value,
                'cycle_number' => $command->cycleNumber,
                'channel_id' => $command->context->channel->value,
                'customer_ref' => (string) $command->customer,
                'payment_method_ref' => $command->paymentMethodRef,
                'currency' => $currency,
                'total_minor' => $totals->total->amountMinor,
                'due_now_minor' => $command->quote->dueNowAmount->amountMinor,
                'is_renewal' => true,
                'lines' => $linesPayload,
            ],
            occurredAt: $command->occurredAt,
        ));

        return RenewalOrderResult::succeeded($orderRef, $command->quote->dueNowAmount);
    }

    #[Override]
    public function find(RenewalOrderId|string $id): ?RenewalOrderView
    {
        $renewalId = $id instanceof RenewalOrderId ? $id->value : strtolower($id);
        $row = $this->db()->table($this->tables->table('subscription_renewal_orders'))
            ->where('id', $renewalId)
            ->first();

        return $row !== null ? SubscriptionRowMapper::renewalOrder($row) : null;
    }

    #[Override]
    public function findByOrderRef(string $orderRef): ?RenewalOrderView
    {
        $row = $this->db()->table($this->tables->table('subscription_renewal_orders'))
            ->where('order_ref', $orderRef)
            ->first();

        return $row !== null ? SubscriptionRowMapper::renewalOrder($row) : null;
    }

    /** @return list<RenewalOrderView> */
    #[Override]
    public function forContract(SubscriptionContractId|string $contractId): array
    {
        $id = $contractId instanceof SubscriptionContractId ? $contractId->value : strtolower($contractId);
        $rows = $this->db()->table($this->tables->table('subscription_renewal_orders'))
            ->where('contract_id', $id)
            ->orderBy('cycle_number')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_map(
            SubscriptionRowMapper::renewalOrder(...),
            $rows,
        ));
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->commerzOptions->connection);
    }
}
