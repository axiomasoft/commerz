<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Lifecycle\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Schema\TableNames;
use Commerz\Subscriptions\Billing\Data\BillingCycleId;
use Commerz\Subscriptions\Billing\Enums\BillingCycleStatus;
use Commerz\Subscriptions\Configuration\SubscriptionsOptions;
use Commerz\Subscriptions\Errors\Exceptions\SubscriptionException;
use Commerz\Subscriptions\Internal\SubscriptionRowMapper;
use Commerz\Subscriptions\Lifecycle\Contracts\SubscriptionContracts;
use Commerz\Subscriptions\Lifecycle\Data\CreateSubscriptionContract;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContract;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContractId;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionLine;
use Commerz\Subscriptions\Lifecycle\Enums\SubscriptionStatus;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Packman\Attributes\Internal;

/**
 * Database-backed manager of {@see SubscriptionContract} lifecycles (`active`, `paused`, `past_due`, `canceled`).
 */
#[Internal]
final readonly class DatabaseSubscriptionContracts implements SubscriptionContracts
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $commerzOptions,
        private SubscriptionsOptions $options,
        private TableNames $tables,
        private Transactions $transactions,
        private Outbox $outbox,
        private Clock $clock,
    ) {}

    #[Override]
    public function create(CreateSubscriptionContract $command): SubscriptionContract
    {
        return $this->transactions->run(function () use ($command): SubscriptionContract {
            $db = $this->db();
            $contractsTable = $this->tables->table('subscription_contracts');
            $linesTable = $this->tables->table('subscription_contract_lines');
            $cyclesTable = $this->tables->table('subscription_billing_cycles');

            if ($command->idempotencyKey !== null) {
                $existing = $db->table($contractsTable)
                    ->where('idempotency_key', $command->idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $hydrated = $this->hydrateOne($db, $existing);
                    if (! $hydrated->customer->equals($command->customer) || $hydrated->sellingPlan->id !== $command->sellingPlan->id) {
                        throw new IdempotencyConflict('subscriptions.create_contract', $command->idempotencyKey);
                    }

                    return $hydrated;
                }
            }

            if ($command->originOrderRef !== null) {
                $query = $db->table($contractsTable)
                    ->where('origin_order_ref', $command->originOrderRef);

                if ($command->originLineId !== null) {
                    $query->where('origin_line_id', $command->originLineId);
                } else {
                    $query->whereNull('origin_line_id');
                }

                $existingOrigin = $query->lockForUpdate()->first();
                if ($existingOrigin !== null) {
                    return $this->hydrateOne($db, $existingOrigin);
                }
            }

            $now = PgTimestamp::current($this->clock);
            $nowTs = PgTimestamp::format($now);
            $startedAt = ($command->startedAt ?? $now)->setTimezone(new DateTimeZone('UTC'));
            $contractId = SubscriptionContractId::generate();
            $retryPolicy = $command->retryPolicy ?? $this->options->retryPolicy;
            $hasInitialOrder = $command->originOrderRef !== null || $command->initialBilledAmount !== null;

            $completedCycles = $hasInitialOrder ? 1 : 0;
            $maxCycles = $command->sellingPlan->maxCycles;

            if (! $hasInitialOrder) {
                $nextBillingAt = $startedAt;
            } elseif ($maxCycles === null || $maxCycles > 1) {
                $nextBillingAt = $command->sellingPlan->nextBillingAt($startedAt, 1);
            } else {
                $nextBillingAt = null;
            }

            $db->table($contractsTable)->insert([
                'id' => $contractId->value,
                'channel_id' => $command->channelId->value,
                'currency' => $command->currency->code,
                'locale' => $command->locale,
                'customer_ref' => (string) $command->customer,
                'status' => SubscriptionStatus::Active->value,
                'payment_method_ref' => $command->paymentMethodRef,
                'origin_order_ref' => $command->originOrderRef,
                'origin_line_id' => $command->originLineId,
                'idempotency_key' => $command->idempotencyKey,
                'selling_plan' => json_encode($command->sellingPlan->toArray(), JSON_THROW_ON_ERROR),
                'retry_policy' => json_encode($retryPolicy->toArray(), JSON_THROW_ON_ERROR),
                'completed_cycles' => $completedCycles,
                'consecutive_failures' => 0,
                'next_billing_at' => $nextBillingAt !== null ? PgTimestamp::format($nextBillingAt) : null,
                'paused_at' => null,
                'canceled_at' => null,
                'cancel_reason' => null,
                'version' => 0,
                'metadata' => json_encode($command->metadata === [] ? (object) [] : $command->metadata, JSON_THROW_ON_ERROR),
                'created_at' => $nowTs,
                'updated_at' => $nowTs,
            ]);

            $estimatedCycleTotal = Money::zero($command->currency);

            foreach ($command->lines as $lineInput) {
                $baseUnitPrice = $lineInput->unitPriceOverride
                    ?? $lineInput->sellable->basePrice
                    ?? ($lineInput->sellable->unitPrice->amount ?? Money::zero($command->currency));

                $cycle1Unit = $command->sellingPlan->unitPriceForCycle($baseUnitPrice, 1);
                $estimatedCycleTotal = $estimatedCycleTotal->plus($cycle1Unit->multiply($lineInput->quantity));

                $db->table($linesTable)->insert([
                    'id' => Uuid7::generate(),
                    'contract_id' => $contractId->value,
                    'line_id' => $lineInput->lineId,
                    'sellable_ref' => (string) $lineInput->sellable->ref,
                    'sku' => $lineInput->sellable->sku,
                    'name' => $lineInput->sellable->name,
                    'kind' => $lineInput->sellable->kind->value,
                    'requires_shipping' => $lineInput->sellable->requiresShipping,
                    'quantity' => $lineInput->quantity,
                    'base_unit_price_minor' => $baseUnitPrice->amountMinor,
                    'tax_class' => $lineInput->sellable->taxClass,
                    'weight_grams' => $lineInput->sellable->weightGrams,
                    'snapshot' => json_encode(SubscriptionRowMapper::serializeSnapshot($lineInput->sellable), JSON_THROW_ON_ERROR),
                    'created_at' => $nowTs,
                    'updated_at' => $nowTs,
                ]);
            }

            if ($hasInitialOrder) {
                $initialAmount = $command->initialBilledAmount ?? $estimatedCycleTotal;
                $startedTs = PgTimestamp::format($startedAt);

                $db->table($cyclesTable)->insert([
                    'id' => BillingCycleId::generate()->value,
                    'contract_id' => $contractId->value,
                    'cycle_number' => 1,
                    'status' => BillingCycleStatus::Billed->value,
                    'scheduled_at' => $startedTs,
                    'next_attempt_at' => null,
                    'attempts' => 1,
                    'currency' => $command->currency->code,
                    'billed_amount_minor' => $initialAmount->amountMinor,
                    'order_ref' => $command->originOrderRef,
                    'last_error' => null,
                    'billed_at' => $startedTs,
                    'created_at' => $nowTs,
                    'updated_at' => $nowTs,
                ]);

                if ($nextBillingAt !== null) {
                    $nextTs = PgTimestamp::format($nextBillingAt);
                    $db->table($cyclesTable)->insert([
                        'id' => BillingCycleId::generate()->value,
                        'contract_id' => $contractId->value,
                        'cycle_number' => 2,
                        'status' => BillingCycleStatus::Scheduled->value,
                        'scheduled_at' => $nextTs,
                        'next_attempt_at' => $nextTs,
                        'attempts' => 0,
                        'currency' => $command->currency->code,
                        'billed_amount_minor' => null,
                        'order_ref' => null,
                        'last_error' => null,
                        'billed_at' => null,
                        'created_at' => $nowTs,
                        'updated_at' => $nowTs,
                    ]);
                }
            } elseif ($nextBillingAt !== null) {
                $nextTs = PgTimestamp::format($nextBillingAt);
                $db->table($cyclesTable)->insert([
                    'id' => BillingCycleId::generate()->value,
                    'contract_id' => $contractId->value,
                    'cycle_number' => 1,
                    'status' => BillingCycleStatus::Scheduled->value,
                    'scheduled_at' => $nextTs,
                    'next_attempt_at' => $nextTs,
                    'attempts' => 0,
                    'currency' => $command->currency->code,
                    'billed_amount_minor' => null,
                    'order_ref' => null,
                    'last_error' => null,
                    'billed_at' => null,
                    'created_at' => $nowTs,
                    'updated_at' => $nowTs,
                ]);
            }

            $this->outbox->record(IntegrationEvent::of(
                'subscriptions.contract_created',
                'subscription_contract:'.$contractId->value,
                [
                    'contract_id' => $contractId->value,
                    'channel_id' => $command->channelId->value,
                    'customer_ref' => (string) $command->customer,
                    'status' => SubscriptionStatus::Active->value,
                    'selling_plan_id' => $command->sellingPlan->id,
                    'origin_order_ref' => $command->originOrderRef,
                    'next_billing_at' => $nextBillingAt?->format('Y-m-d\TH:i:s.uP'),
                ],
                occurredAt: $now,
            ));

            return $this->getLocked($db, $contractId->value);
        });
    }

    #[Override]
    public function find(SubscriptionContractId|string $id): ?SubscriptionContract
    {
        $contractId = $id instanceof SubscriptionContractId ? $id->value : strtolower($id);
        $db = $this->db();
        $row = $db->table($this->tables->table('subscription_contracts'))
            ->where('id', $contractId)
            ->first();

        return $row !== null ? $this->hydrateOne($db, $row) : null;
    }

    #[Override]
    public function get(SubscriptionContractId|string $id): SubscriptionContract
    {
        $contractId = $id instanceof SubscriptionContractId ? $id->value : strtolower($id);

        return $this->find($contractId) ?? throw SubscriptionException::contractNotFound($contractId);
    }

    /** @return list<SubscriptionContract> */
    #[Override]
    public function forCustomer(CustomerRef $customer): array
    {
        $db = $this->db();
        $rows = $db->table($this->tables->table('subscription_contracts'))
            ->where('customer_ref', (string) $customer)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        return $this->hydrateMany($db, $rows);
    }

    /** @return list<SubscriptionContract> */
    #[Override]
    public function forOriginOrder(string $originOrderRef): array
    {
        $db = $this->db();
        $rows = $db->table($this->tables->table('subscription_contracts'))
            ->where('origin_order_ref', $originOrderRef)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        return $this->hydrateMany($db, $rows);
    }

    #[Override]
    public function pause(SubscriptionContractId|string $id, ?string $reason = null): SubscriptionContract
    {
        $contractId = $id instanceof SubscriptionContractId ? $id->value : strtolower($id);

        return $this->transactions->run(function () use ($contractId, $reason): SubscriptionContract {
            $db = $this->db();
            $contract = $this->lockContract($db, $contractId);

            if ($contract->status === SubscriptionStatus::Paused) {
                return $contract;
            }

            if (! $contract->status->canTransitionTo(SubscriptionStatus::Paused)) {
                throw SubscriptionException::invalidTransition(
                    $contract->id->value,
                    $contract->status->value,
                    SubscriptionStatus::Paused->value,
                    'canceled contracts cannot be paused',
                );
            }

            $now = PgTimestamp::current($this->clock);
            $nowTs = PgTimestamp::format($now);

            $db->table($this->tables->table('subscription_contracts'))
                ->where('id', $contract->id->value)
                ->update([
                    'status' => SubscriptionStatus::Paused->value,
                    'paused_at' => $nowTs,
                    'next_billing_at' => null,
                    'version' => $contract->version + 1,
                    'updated_at' => $nowTs,
                ]);

            $db->table($this->tables->table('subscription_billing_cycles'))
                ->where('contract_id', $contract->id->value)
                ->whereIn('status', [BillingCycleStatus::Scheduled->value, BillingCycleStatus::Retrying->value])
                ->update([
                    'next_attempt_at' => null,
                    'updated_at' => $nowTs,
                ]);

            $this->outbox->record(IntegrationEvent::of(
                'subscriptions.contract_paused',
                'subscription_contract:'.$contract->id->value,
                [
                    'contract_id' => $contract->id->value,
                    'reason' => $reason,
                ],
                occurredAt: $now,
            ));

            return $this->getLocked($db, $contract->id->value);
        });
    }

    #[Override]
    public function resume(SubscriptionContractId|string $id, ?DateTimeImmutable $nextBillingAt = null): SubscriptionContract
    {
        $contractId = $id instanceof SubscriptionContractId ? $id->value : strtolower($id);

        return $this->transactions->run(function () use ($contractId, $nextBillingAt): SubscriptionContract {
            $db = $this->db();
            $contract = $this->lockContract($db, $contractId);

            if ($contract->status === SubscriptionStatus::Active && $nextBillingAt === null) {
                return $contract;
            }

            if (! $contract->status->canTransitionTo(SubscriptionStatus::Active)) {
                throw SubscriptionException::invalidTransition(
                    $contract->id->value,
                    $contract->status->value,
                    SubscriptionStatus::Active->value,
                    'canceled contracts cannot be resumed',
                );
            }

            $now = PgTimestamp::current($this->clock);
            $nowTs = PgTimestamp::format($now);
            $cyclesTable = $this->tables->table('subscription_billing_cycles');

            $openCycle = $db->table($cyclesTable)
                ->where('contract_id', $contract->id->value)
                ->whereIn('status', [BillingCycleStatus::Scheduled->value, BillingCycleStatus::Retrying->value])
                ->orderBy('cycle_number')
                ->lockForUpdate()
                ->first();

            if ($nextBillingAt !== null) {
                $resolvedNext = $nextBillingAt->setTimezone(new DateTimeZone('UTC'));
            } elseif ($openCycle !== null) {
                $scheduledAt = SubscriptionRowMapper::instant($openCycle, 'scheduled_at');
                $resolvedNext = $scheduledAt > $now ? $scheduledAt : $now;
            } else {
                $resolvedNext = $contract->sellingPlan->nextBillingAt($now, 1);
            }

            $resolvedNextTs = PgTimestamp::format($resolvedNext);

            if ($openCycle !== null) {
                $db->table($cyclesTable)
                    ->where('id', SubscriptionRowMapper::string($openCycle, 'id'))
                    ->update([
                        'status' => BillingCycleStatus::Scheduled->value,
                        'scheduled_at' => $resolvedNextTs,
                        'next_attempt_at' => $resolvedNextTs,
                        'updated_at' => $nowTs,
                    ]);
            } elseif ($contract->sellingPlan->maxCycles === null || $contract->completedCycles < $contract->sellingPlan->maxCycles) {
                $db->table($cyclesTable)->insert([
                    'id' => BillingCycleId::generate()->value,
                    'contract_id' => $contract->id->value,
                    'cycle_number' => $contract->completedCycles + 1,
                    'status' => BillingCycleStatus::Scheduled->value,
                    'scheduled_at' => $resolvedNextTs,
                    'next_attempt_at' => $resolvedNextTs,
                    'attempts' => 0,
                    'currency' => $contract->currency->code,
                    'billed_amount_minor' => null,
                    'order_ref' => null,
                    'last_error' => null,
                    'billed_at' => null,
                    'created_at' => $nowTs,
                    'updated_at' => $nowTs,
                ]);
            } else {
                $resolvedNext = null;
            }

            $db->table($this->tables->table('subscription_contracts'))
                ->where('id', $contract->id->value)
                ->update([
                    'status' => SubscriptionStatus::Active->value,
                    'paused_at' => null,
                    'consecutive_failures' => 0,
                    'next_billing_at' => $resolvedNext !== null ? PgTimestamp::format($resolvedNext) : null,
                    'version' => $contract->version + 1,
                    'updated_at' => $nowTs,
                ]);

            $this->outbox->record(IntegrationEvent::of(
                'subscriptions.contract_resumed',
                'subscription_contract:'.$contract->id->value,
                [
                    'contract_id' => $contract->id->value,
                    'next_billing_at' => $resolvedNext?->format('Y-m-d\TH:i:s.uP'),
                ],
                occurredAt: $now,
            ));

            return $this->getLocked($db, $contract->id->value);
        });
    }

    #[Override]
    public function cancel(SubscriptionContractId|string $id, ?string $reason = null): SubscriptionContract
    {
        $contractId = $id instanceof SubscriptionContractId ? $id->value : strtolower($id);

        return $this->transactions->run(function () use ($contractId, $reason): SubscriptionContract {
            $db = $this->db();
            $contract = $this->lockContract($db, $contractId);

            if ($contract->status === SubscriptionStatus::Canceled) {
                return $contract;
            }

            $now = PgTimestamp::current($this->clock);
            $nowTs = PgTimestamp::format($now);
            $cleanReason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 191, 'UTF-8') : null;

            $db->table($this->tables->table('subscription_contracts'))
                ->where('id', $contract->id->value)
                ->update([
                    'status' => SubscriptionStatus::Canceled->value,
                    'canceled_at' => $nowTs,
                    'cancel_reason' => $cleanReason,
                    'next_billing_at' => null,
                    'version' => $contract->version + 1,
                    'updated_at' => $nowTs,
                ]);

            $db->table($this->tables->table('subscription_billing_cycles'))
                ->where('contract_id', $contract->id->value)
                ->whereIn('status', [BillingCycleStatus::Scheduled->value, BillingCycleStatus::Retrying->value])
                ->update([
                    'status' => BillingCycleStatus::Canceled->value,
                    'next_attempt_at' => null,
                    'updated_at' => $nowTs,
                ]);

            $this->outbox->record(IntegrationEvent::of(
                'subscriptions.contract_canceled',
                'subscription_contract:'.$contract->id->value,
                [
                    'contract_id' => $contract->id->value,
                    'reason' => $cleanReason,
                ],
                occurredAt: $now,
            ));

            return $this->getLocked($db, $contract->id->value);
        });
    }

    #[Override]
    public function markPastDue(SubscriptionContractId|string $id, ?string $reason = null): SubscriptionContract
    {
        $contractId = $id instanceof SubscriptionContractId ? $id->value : strtolower($id);

        return $this->transactions->run(function () use ($contractId, $reason): SubscriptionContract {
            $db = $this->db();
            $contract = $this->lockContract($db, $contractId);

            if ($contract->status === SubscriptionStatus::PastDue) {
                return $contract;
            }

            if (! $contract->status->canTransitionTo(SubscriptionStatus::PastDue)) {
                throw SubscriptionException::invalidTransition(
                    $contract->id->value,
                    $contract->status->value,
                    SubscriptionStatus::PastDue->value,
                    "cannot mark '{$contract->status->value}' contract as past_due",
                );
            }

            $now = PgTimestamp::current($this->clock);
            $nowTs = PgTimestamp::format($now);

            $db->table($this->tables->table('subscription_contracts'))
                ->where('id', $contract->id->value)
                ->update([
                    'status' => SubscriptionStatus::PastDue->value,
                    'version' => $contract->version + 1,
                    'updated_at' => $nowTs,
                ]);

            $this->outbox->record(IntegrationEvent::of(
                'subscriptions.contract_past_due',
                'subscription_contract:'.$contract->id->value,
                [
                    'contract_id' => $contract->id->value,
                    'reason' => $reason,
                ],
                occurredAt: $now,
            ));

            return $this->getLocked($db, $contract->id->value);
        });
    }

    #[Override]
    public function updatePaymentMethod(SubscriptionContractId|string $id, string $paymentMethodRef): SubscriptionContract
    {
        if (trim($paymentMethodRef) === '' || strlen($paymentMethodRef) > 191) {
            throw new InvalidCommand('paymentMethodRef', 'must be 1..191 non-blank bytes', 'subscriptions.invalid_command');
        }

        $contractId = $id instanceof SubscriptionContractId ? $id->value : strtolower($id);

        return $this->transactions->run(function () use ($contractId, $paymentMethodRef): SubscriptionContract {
            $db = $this->db();
            $contract = $this->lockContract($db, $contractId);

            if ($contract->status === SubscriptionStatus::Canceled) {
                throw SubscriptionException::invalidTransition(
                    $contract->id->value,
                    $contract->status->value,
                    $contract->status->value,
                    'cannot update payment method of a canceled contract',
                );
            }

            $nowTs = PgTimestamp::now($this->clock);

            $db->table($this->tables->table('subscription_contracts'))
                ->where('id', $contract->id->value)
                ->update([
                    'payment_method_ref' => trim($paymentMethodRef),
                    'version' => $contract->version + 1,
                    'updated_at' => $nowTs,
                ]);

            return $this->getLocked($db, $contract->id->value);
        });
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->commerzOptions->connection);
    }

    private function lockContract(ConnectionInterface $db, string $id): SubscriptionContract
    {
        $row = $db->table($this->tables->table('subscription_contracts'))
            ->where('id', $id)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw SubscriptionException::contractNotFound($id);
        }

        return $this->hydrateOne($db, $row);
    }

    private function getLocked(ConnectionInterface $db, string $id): SubscriptionContract
    {
        $row = $db->table($this->tables->table('subscription_contracts'))
            ->where('id', $id)
            ->first();

        if ($row === null) {
            throw SubscriptionException::contractNotFound($id);
        }

        return $this->hydrateOne($db, $row);
    }

    private function hydrateOne(ConnectionInterface $db, object $row): SubscriptionContract
    {
        $contractId = SubscriptionRowMapper::string($row, 'id');
        $currency = Currency::of(SubscriptionRowMapper::string($row, 'currency'));
        $lineRows = $db->table($this->tables->table('subscription_contract_lines'))
            ->where('contract_id', $contractId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        $lines = array_values(array_map(
            static fn (object $lineRow): SubscriptionLine => SubscriptionRowMapper::line($lineRow, $currency),
            $lineRows,
        ));

        return SubscriptionRowMapper::contract($row, $lines);
    }

    /**
     * @param  array<array-key, object>  $rows
     * @return list<SubscriptionContract>
     */
    private function hydrateMany(ConnectionInterface $db, array $rows): array
    {
        $objects = array_values($rows);
        $ids = array_map(
            static fn (object $row): string => SubscriptionRowMapper::string($row, 'id'),
            $objects,
        );

        if ($objects === []) {
            return [];
        }

        $lineRows = $db->table($this->tables->table('subscription_contract_lines'))
            ->whereIn('contract_id', $ids)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        /** @var array<string, list<object>> $grouped */
        $grouped = [];
        foreach ($lineRows as $lineRow) {
            $grouped[SubscriptionRowMapper::string($lineRow, 'contract_id')][] = $lineRow;
        }

        $contracts = [];
        foreach ($objects as $row) {
            $id = SubscriptionRowMapper::string($row, 'id');
            $currency = Currency::of(SubscriptionRowMapper::string($row, 'currency'));
            $lines = array_map(
                static fn (object $l): SubscriptionLine => SubscriptionRowMapper::line($l, $currency),
                $grouped[$id] ?? [],
            );
            $contracts[] = SubscriptionRowMapper::contract($row, $lines);
        }

        return $contracts;
    }
}
