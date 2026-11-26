<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Billing\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Schema\TableNames;
use Commerz\Sellable\SellingPlan;
use Commerz\Subscriptions\Billing\Contracts\BillingSchedule;
use Commerz\Subscriptions\Billing\Data\BillingCycle;
use Commerz\Subscriptions\Billing\Data\BillingCycleId;
use Commerz\Subscriptions\Billing\Data\BillingRunReport;
use Commerz\Subscriptions\Billing\Data\RetryPolicy;
use Commerz\Subscriptions\Billing\Enums\BillingCycleStatus;
use Commerz\Subscriptions\Billing\Enums\RetryExhaustedAction;
use Commerz\Subscriptions\Configuration\SubscriptionsOptions;
use Commerz\Subscriptions\Errors\Exceptions\SubscriptionException;
use Commerz\Subscriptions\Internal\SubscriptionRowMapper;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContract;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContractId;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionLine;
use Commerz\Subscriptions\Lifecycle\Enums\SubscriptionStatus;
use Commerz\Subscriptions\Renewals\Contracts\RenewalOrderCreator;
use Commerz\Subscriptions\Renewals\Data\PlaceRenewalOrder;
use Commerz\Subscriptions\Renewals\Data\RenewalOrderResult;
use Commerz\Subscriptions\Renewals\Exceptions\RenewalPaymentFailed;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Packman\Attributes\Internal;

/**
 * Executes due {@see BillingCycle}s, quotes renewal lines via {@see QuotePipeline}, invokes {@see RenewalOrderCreator},
 * and enforces the contract's dunning {@see RetryPolicy}.
 */
#[Internal]
final readonly class DatabaseBillingSchedule implements BillingSchedule
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $commerzOptions,
        private SubscriptionsOptions $options,
        private TableNames $tables,
        private Transactions $transactions,
        private QuotePipeline $quotes,
        private Container $container,
        private Outbox $outbox,
        private Clock $clock,
    ) {}

    #[Override]
    public function find(BillingCycleId|string $id): ?BillingCycle
    {
        $cycleId = $id instanceof BillingCycleId ? $id->value : strtolower($id);
        $row = $this->db()->table($this->tables->table('subscription_billing_cycles'))
            ->where('id', $cycleId)
            ->first();

        return $row !== null ? SubscriptionRowMapper::cycle($row) : null;
    }

    #[Override]
    public function get(BillingCycleId|string $id): BillingCycle
    {
        $cycleId = $id instanceof BillingCycleId ? $id->value : strtolower($id);

        return $this->find($cycleId) ?? throw SubscriptionException::cycleNotFound($cycleId);
    }

    /** @return list<BillingCycle> */
    #[Override]
    public function forContract(SubscriptionContractId|string $contractId): array
    {
        $id = $contractId instanceof SubscriptionContractId ? $contractId->value : strtolower($contractId);
        $rows = $this->db()->table($this->tables->table('subscription_billing_cycles'))
            ->where('contract_id', $id)
            ->orderBy('cycle_number')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_map(
            SubscriptionRowMapper::cycle(...),
            $rows,
        ));
    }

    /** @return list<BillingCycle> */
    #[Override]
    public function dueCycles(?DateTimeImmutable $asOf = null, ?int $limit = null): array
    {
        $effectiveLimit = $limit ?? $this->options->batchSize;
        if ($effectiveLimit < 1 || $effectiveLimit > 1000) {
            throw new InvalidCommand('limit', 'must be between 1 and 1000', 'subscriptions.invalid_command');
        }

        $cutoff = PgTimestamp::format($asOf ?? PgTimestamp::current($this->clock));
        $cyclesTable = $this->tables->table('subscription_billing_cycles');
        $contractsTable = $this->tables->table('subscription_contracts');

        $rows = $this->db()->table($cyclesTable.' as c')
            ->join($contractsTable.' as sc', 'sc.id', '=', 'c.contract_id')
            ->whereIn('c.status', [BillingCycleStatus::Scheduled->value, BillingCycleStatus::Retrying->value])
            ->whereNotNull('c.next_attempt_at')
            ->where('c.next_attempt_at', '<=', $cutoff)
            ->whereIn('sc.status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->orderBy('c.next_attempt_at')
            ->orderBy('c.id')
            ->limit($effectiveLimit)
            ->select('c.*')
            ->get()
            ->all();

        return array_values(array_map(
            SubscriptionRowMapper::cycle(...),
            $rows,
        ));
    }

    #[Override]
    public function processDue(?int $limit = null): BillingRunReport
    {
        $due = $this->dueCycles(limit: $limit);
        $processedCycles = [];
        $billed = 0;
        $retrying = 0;
        $exhausted = 0;

        foreach ($due as $candidate) {
            $updated = $this->processCycle($candidate->id);
            $processedCycles[] = $updated;

            match ($updated->status) {
                BillingCycleStatus::Billed => $billed++,
                BillingCycleStatus::Retrying => $retrying++,
                BillingCycleStatus::Failed, BillingCycleStatus::Canceled => $exhausted++,
                default => null,
            };
        }

        return new BillingRunReport(
            processed: count($processedCycles),
            billed: $billed,
            retrying: $retrying,
            exhausted: $exhausted,
            cycles: $processedCycles,
        );
    }

    #[Override]
    public function processCycle(BillingCycleId|string $cycleId): BillingCycle
    {
        $id = $cycleId instanceof BillingCycleId ? $cycleId->value : strtolower($cycleId);

        return $this->transactions->run(function () use ($id): BillingCycle {
            $db = $this->db();
            $cyclesTable = $this->tables->table('subscription_billing_cycles');
            $contractsTable = $this->tables->table('subscription_contracts');

            $cycleRow = $db->table($cyclesTable)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($cycleRow === null) {
                throw SubscriptionException::cycleNotFound($id);
            }

            $cycle = SubscriptionRowMapper::cycle($cycleRow);
            if (! $cycle->status->isRunnable()) {
                throw SubscriptionException::cycleAlreadyProcessed($cycle->id->value, $cycle->status->value);
            }

            $contractRow = $db->table($contractsTable)
                ->where('id', $cycle->contractId->value)
                ->lockForUpdate()
                ->first();

            if ($contractRow === null) {
                throw SubscriptionException::contractNotFound($cycle->contractId->value);
            }

            $contract = $this->hydrateContract($db, $contractRow);
            if (! in_array($contract->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
                throw SubscriptionException::invalidTransition(
                    $contract->id->value,
                    $contract->status->value,
                    $contract->status->value,
                    "cannot process billing cycle while contract is '{$contract->status->value}'",
                );
            }

            $now = PgTimestamp::current($this->clock);
            $nowTs = PgTimestamp::format($now);
            $attempts = $cycle->attempts + 1;

            $quote = $this->quoteCycle($contract, $cycle->cycleNumber, $now);
            $idempotencyKey = "subscription:{$contract->id->value}:cycle:{$cycle->cycleNumber}";
            $context = CommerceContext::for($contract->channelId, $contract->currency, $contract->locale, $now)
                ->withCustomer($contract->customer);

            try {
                $result = $this->container->make(RenewalOrderCreator::class)->placeRenewalOrder(new PlaceRenewalOrder(
                    contractId: $contract->id,
                    cycleId: $cycle->id,
                    cycleNumber: $cycle->cycleNumber,
                    idempotencyKey: $idempotencyKey,
                    context: $context,
                    customer: $contract->customer,
                    paymentMethodRef: $contract->paymentMethodRef,
                    sellingPlan: $contract->sellingPlan,
                    quote: $quote,
                    occurredAt: $now,
                ));
            } catch (RenewalPaymentFailed $failed) {
                $result = RenewalOrderResult::failed($failed->reason);
            }

            if ($result->succeeded && $result->orderRef !== null) {
                $this->recordSuccess($db, $contract, $cycle, $attempts, $result, $quote, $now, $nowTs);
            } else {
                $this->recordFailure($db, $contract, $cycle, $attempts, $result->failureReason ?? 'renewal_failed', $now, $nowTs);
            }

            $updatedRow = $db->table($cyclesTable)->where('id', $cycle->id->value)->first();
            if ($updatedRow === null) {
                throw SubscriptionException::cycleNotFound($cycle->id->value);
            }

            return SubscriptionRowMapper::cycle($updatedRow);
        });
    }

    private function quoteCycle(SubscriptionContract $contract, int $cycleNumber, DateTimeImmutable $at): QuoteResult
    {
        $cyclePlan = $this->planForCycle($contract->sellingPlan, $cycleNumber);
        $context = CommerceContext::for($contract->channelId, $contract->currency, $contract->locale, $at)
            ->withCustomer($contract->customer);

        $lineInputs = array_map(
            static fn (SubscriptionLine $line): QuoteLineInput => new QuoteLineInput(
                lineId: $line->lineId,
                sellable: $line->sellable,
                quantity: $line->quantity,
                sellingPlan: $cyclePlan,
            ),
            $contract->lines,
        );

        return $this->quotes->run(new QuoteInput(
            context: $context,
            lines: $lineInputs,
            sellingPlan: $cyclePlan,
        ));
    }

    private function planForCycle(SellingPlan $plan, int $cycleNumber): SellingPlan
    {
        $pricing = $plan->pricingPolicy;
        if ($pricing->afterCycle === null || $pricing->afterCyclePolicy === null || $cycleNumber <= $pricing->afterCycle) {
            return $plan;
        }

        return new SellingPlan(
            id: $plan->id,
            name: $plan->name,
            billingPolicy: $plan->billingPolicy,
            pricingPolicy: $pricing->afterCyclePolicy,
            deliveryPolicy: $plan->deliveryPolicy,
            requiresSavedPaymentMethod: $plan->requiresSavedPaymentMethod,
            billingInterval: $plan->billingInterval,
            billingIntervalCount: $plan->billingIntervalCount,
            minCycles: $plan->minCycles,
            maxCycles: $plan->maxCycles,
        );
    }

    private function recordSuccess(
        ConnectionInterface $db,
        SubscriptionContract $contract,
        BillingCycle $cycle,
        int $attempts,
        RenewalOrderResult $result,
        QuoteResult $quote,
        DateTimeImmutable $now,
        string $nowTs,
    ): void {
        $cyclesTable = $this->tables->table('subscription_billing_cycles');
        $contractsTable = $this->tables->table('subscription_contracts');
        $charged = $result->chargedAmount ?? $quote->dueNowAmount;

        $db->table($cyclesTable)
            ->where('id', $cycle->id->value)
            ->update([
                'status' => BillingCycleStatus::Billed->value,
                'attempts' => $attempts,
                'next_attempt_at' => null,
                'billed_amount_minor' => $charged->amountMinor,
                'order_ref' => $result->orderRef,
                'last_error' => null,
                'billed_at' => $nowTs,
                'updated_at' => $nowTs,
            ]);

        $completedCycles = max($contract->completedCycles, $cycle->cycleNumber);
        $maxCycles = $contract->sellingPlan->maxCycles;
        $nextScheduledAt = null;

        if ($maxCycles === null || $completedCycles < $maxCycles) {
            $nextCycleNumber = $cycle->cycleNumber + 1;
            $nextScheduledAt = $contract->sellingPlan->nextBillingAt($cycle->scheduledAt, 1);
            $nextTs = PgTimestamp::format($nextScheduledAt);

            $existingNext = $db->table($cyclesTable)
                ->where('contract_id', $contract->id->value)
                ->where('cycle_number', $nextCycleNumber)
                ->exists();

            if (! $existingNext) {
                $db->table($cyclesTable)->insert([
                    'id' => BillingCycleId::generate()->value,
                    'contract_id' => $contract->id->value,
                    'cycle_number' => $nextCycleNumber,
                    'status' => BillingCycleStatus::Scheduled->value,
                    'scheduled_at' => $nextTs,
                    'next_attempt_at' => $nextTs,
                    'attempts' => 0,
                    'currency' => $contract->currency->code,
                    'billed_amount_minor' => null,
                    'order_ref' => null,
                    'last_error' => null,
                    'billed_at' => null,
                    'created_at' => $nowTs,
                    'updated_at' => $nowTs,
                ]);
            }
        }

        $db->table($contractsTable)
            ->where('id', $contract->id->value)
            ->update([
                'status' => SubscriptionStatus::Active->value,
                'completed_cycles' => $completedCycles,
                'consecutive_failures' => 0,
                'next_billing_at' => $nextScheduledAt !== null ? PgTimestamp::format($nextScheduledAt) : null,
                'version' => $contract->version + 1,
                'updated_at' => $nowTs,
            ]);

        $this->outbox->record(IntegrationEvent::of(
            'subscriptions.cycle_billed',
            'subscription_contract:'.$contract->id->value,
            [
                'contract_id' => $contract->id->value,
                'cycle_id' => $cycle->id->value,
                'cycle_number' => $cycle->cycleNumber,
                'order_ref' => $result->orderRef,
                'billed_amount_minor' => $charged->amountMinor,
                'currency' => $contract->currency->code,
                'next_billing_at' => $nextScheduledAt?->format('Y-m-d\TH:i:s.uP'),
            ],
            occurredAt: $now,
        ));
    }

    private function recordFailure(
        ConnectionInterface $db,
        SubscriptionContract $contract,
        BillingCycle $cycle,
        int $attempts,
        string $reason,
        DateTimeImmutable $now,
        string $nowTs,
    ): void {
        $cyclesTable = $this->tables->table('subscription_billing_cycles');
        $contractsTable = $this->tables->table('subscription_contracts');
        $cleanReason = mb_substr($reason, 0, 500, 'UTF-8');
        $failures = $contract->consecutiveFailures + 1;
        $nextAttemptAt = $contract->retryPolicy->nextAttemptAt($now, $attempts);

        if ($nextAttemptAt !== null) {
            $nextAttemptTs = PgTimestamp::format($nextAttemptAt);

            $db->table($cyclesTable)
                ->where('id', $cycle->id->value)
                ->update([
                    'status' => BillingCycleStatus::Retrying->value,
                    'attempts' => $attempts,
                    'next_attempt_at' => $nextAttemptTs,
                    'last_error' => $cleanReason,
                    'updated_at' => $nowTs,
                ]);

            $db->table($contractsTable)
                ->where('id', $contract->id->value)
                ->update([
                    'status' => SubscriptionStatus::PastDue->value,
                    'consecutive_failures' => $failures,
                    'next_billing_at' => $nextAttemptTs,
                    'version' => $contract->version + 1,
                    'updated_at' => $nowTs,
                ]);

            $this->outbox->record(IntegrationEvent::of(
                'subscriptions.billing_retry_scheduled',
                'subscription_contract:'.$contract->id->value,
                [
                    'contract_id' => $contract->id->value,
                    'cycle_id' => $cycle->id->value,
                    'cycle_number' => $cycle->cycleNumber,
                    'attempts' => $attempts,
                    'next_attempt_at' => $nextAttemptAt->format('Y-m-d\TH:i:s.uP'),
                    'reason' => $cleanReason,
                ],
                occurredAt: $now,
            ));

            return;
        }

        $db->table($cyclesTable)
            ->where('id', $cycle->id->value)
            ->update([
                'status' => BillingCycleStatus::Failed->value,
                'attempts' => $attempts,
                'next_attempt_at' => null,
                'last_error' => $cleanReason,
                'updated_at' => $nowTs,
            ]);

        $contractUpdates = match ($contract->retryPolicy->exhaustedAction) {
            RetryExhaustedAction::Cancel => [
                'status' => SubscriptionStatus::Canceled->value,
                'canceled_at' => $nowTs,
                'cancel_reason' => 'billing_retries_exhausted',
                'next_billing_at' => null,
            ],
            RetryExhaustedAction::Pause => [
                'status' => SubscriptionStatus::Paused->value,
                'paused_at' => $nowTs,
                'next_billing_at' => null,
            ],
            RetryExhaustedAction::KeepPastDue => [
                'status' => SubscriptionStatus::PastDue->value,
                'next_billing_at' => null,
            ],
        };

        $db->table($contractsTable)
            ->where('id', $contract->id->value)
            ->update([
                ...$contractUpdates,
                'consecutive_failures' => $failures,
                'version' => $contract->version + 1,
                'updated_at' => $nowTs,
            ]);

        $this->outbox->record(IntegrationEvent::of(
            'subscriptions.billing_retries_exhausted',
            'subscription_contract:'.$contract->id->value,
            [
                'contract_id' => $contract->id->value,
                'cycle_id' => $cycle->id->value,
                'cycle_number' => $cycle->cycleNumber,
                'attempts' => $attempts,
                'exhausted_action' => $contract->retryPolicy->exhaustedAction->value,
                'reason' => $cleanReason,
            ],
            occurredAt: $now,
        ));
    }

    private function hydrateContract(ConnectionInterface $db, object $row): SubscriptionContract
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

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->commerzOptions->connection);
    }
}
