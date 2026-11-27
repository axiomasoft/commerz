<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Closure;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\StoredValue\Data\StoredValueCapture;
use Commerz\Ports\StoredValue\Data\StoredValueHold;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Sales\Payments\Data\AddStoredValueSession;
use Commerz\Sales\Payments\Data\AuthorizeGatewaySession;
use Commerz\Sales\Payments\Data\CapturePaymentSession;
use Commerz\Sales\Payments\Data\CreatePaymentCollection;
use Commerz\Sales\Payments\Data\PaymentCaptureId;
use Commerz\Sales\Payments\Data\PaymentCaptureView;
use Commerz\Sales\Payments\Data\PaymentCollectionId;
use Commerz\Sales\Payments\Data\PaymentCollectionView;
use Commerz\Sales\Payments\Data\PaymentSessionId;
use Commerz\Sales\Payments\Enums\PaymentCaptureStatus;
use Commerz\Sales\Payments\Enums\PaymentCollectionStatus;
use Commerz\Sales\Payments\Enums\PaymentSessionKind;
use Commerz\Sales\Payments\Enums\PaymentSessionStatus;
use Commerz\Sales\Payments\Events\PaymentCollectionStatusChanged;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentSessionState;
use Commerz\Sales\Payments\Exceptions\OrderNotPayable;
use Commerz\Sales\Payments\Exceptions\PaymentCollectionNotFound;
use Commerz\Sales\Payments\Exceptions\PaymentExceedsBalance;
use Commerz\Sales\Payments\Exceptions\PaymentSessionNotFound;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/**
 * Sole persistence writer for `payment_collections`, `payment_sessions`, and `payment_captures`.
 */
#[Internal]
final readonly class PaymentCollectionJournal
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private Clock $clock,
    ) {}

    public function create(CreatePaymentCollection $command): PaymentCollectionRow
    {
        $db = $this->db();

        if ($command->order !== null) {
            $order = $db->table($this->tables->table('orders'))
                ->where('id', $command->order->value)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw new OrderNotFound($command->order->value);
            }

            $state = OrderState::from(Rows::string($order, 'state'));

            if ($state !== OrderState::Placed) {
                throw new OrderNotPayable($command->order->value, $state->value);
            }

            $orderCurrency = Rows::string($order, 'currency');

            if ($orderCurrency !== $command->amount->currency->code) {
                throw new InvalidCommand('amount', "must be in {$orderCurrency}, the currency of the order", 'sales.currency_mismatch');
            }
        }

        $id = Uuid7::generate();
        $now = PgTimestamp::now($this->clock);

        $db->table($this->tables->table('payment_collections'))->insert([
            'id' => $id,
            'kind' => $command->kind->value,
            'target_ref' => $command->targetRef,
            'order_id' => $command->order?->value,
            'status' => PaymentCollectionStatus::NotPaid->value,
            'currency' => $command->amount->currency->code,
            'amount_minor' => $command->amount->amountMinor,
            'authorized_minor' => 0,
            'captured_minor' => 0,
            'customer_ref' => $command->customer !== null ? (string) $command->customer : null,
            'customer_email' => $command->customerEmail,
            'meta' => PaymentJournal::json($command->meta),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->get(new PaymentCollectionId($id));
    }

    /** @throws PaymentCollectionNotFound */
    public function get(PaymentCollectionId $id): PaymentCollectionRow
    {
        $row = $this->db()->table($this->tables->table('payment_collections'))->where('id', $id->value)->first();

        return PaymentCollectionRow::fromRow($row ?? throw new PaymentCollectionNotFound($id->value));
    }

    /** @throws PaymentCollectionNotFound */
    public function lock(PaymentCollectionId $id): PaymentCollectionRow
    {
        $row = $this->db()->table($this->tables->table('payment_collections'))->where('id', $id->value)->lockForUpdate()->first();

        return PaymentCollectionRow::fromRow($row ?? throw new PaymentCollectionNotFound($id->value));
    }

    /** @throws PaymentSessionNotFound */
    public function getSession(PaymentSessionId $id): PaymentSessionRow
    {
        $row = $this->db()->table($this->tables->table('payment_sessions'))->where('id', $id->value)->first();

        return PaymentSessionRow::fromRow($row ?? throw new PaymentSessionNotFound($id->value));
    }

    /** @throws PaymentSessionNotFound */
    public function lockSession(PaymentSessionId $id): PaymentSessionRow
    {
        $row = $this->db()->table($this->tables->table('payment_sessions'))->where('id', $id->value)->lockForUpdate()->first();

        return PaymentSessionRow::fromRow($row ?? throw new PaymentSessionNotFound($id->value));
    }

    /** @throws PaymentSessionNotFound */
    public function getCapture(PaymentCaptureId $id): PaymentCaptureRow
    {
        $row = $this->db()->table($this->tables->table('payment_captures'))->where('id', $id->value)->first();

        return PaymentCaptureRow::fromRow($row ?? throw new PaymentSessionNotFound($id->value));
    }

    /** @return list<PaymentSessionRow> */
    public function sessions(PaymentCollectionId $collection): array
    {
        $rows = $this->db()->table($this->tables->table('payment_sessions'))
            ->where('collection_id', $collection->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_map(PaymentSessionRow::fromRow(...), $rows));
    }

    /** @return list<PaymentCaptureRow> */
    public function captures(PaymentSessionId $session): array
    {
        $rows = $this->db()->table($this->tables->table('payment_captures'))
            ->where('session_id', $session->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_map(PaymentCaptureRow::fromRow(...), $rows));
    }

    public function succeededCaptureCount(PaymentSessionId $session): int
    {
        return $this->db()->table($this->tables->table('payment_captures'))
            ->where('session_id', $session->value)
            ->where('status', PaymentCaptureStatus::Succeeded->value)
            ->count();
    }

    public function orderNumber(PaymentCollectionRow $collection): ?string
    {
        if ($collection->order === null) {
            return null;
        }

        $value = $this->db()->table($this->tables->table('orders'))->where('id', $collection->order->value)->value('number');

        return is_string($value) ? $value : null;
    }

    public function externalIdTaken(PaymentSessionRow $session, string $externalId): bool
    {
        if ($session->gateway === null) {
            return false;
        }

        return $this->db()->table($this->tables->table('payment_sessions'))
            ->where('gateway', $session->gateway)
            ->where('external_id', $externalId)
            ->where('id', '<>', $session->id->value)
            ->exists();
    }

    /** @return list<PaymentCollectionView> */
    public function forOrder(OrderId $order): array
    {
        $rows = $this->db()->table($this->tables->table('payment_collections'))
            ->where('order_id', $order->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_map(fn (object $row): PaymentCollectionView => $this->assemble(PaymentCollectionRow::fromRow($row)), $rows));
    }

    /** @return list<PaymentCollectionView> */
    public function forTarget(string $targetRef): array
    {
        $rows = $this->db()->table($this->tables->table('payment_collections'))
            ->where('target_ref', $targetRef)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_map(fn (object $row): PaymentCollectionView => $this->assemble(PaymentCollectionRow::fromRow($row)), $rows));
    }

    public function view(PaymentCollectionId $id): PaymentCollectionView
    {
        return $this->assemble($this->get($id));
    }

    /**
     * Validates remaining collection balance under the collection lock and returns the resolved session amount.
     */
    public function resolveSessionAmount(PaymentCollectionRow $collection, ?Money $requested): Money
    {
        if (! PaymentCollectionStateMachine::canAcceptNewSession($collection->status)) {
            throw new InvalidPaymentSessionState($collection->id->value, $collection->status->value, 'collection is no longer accepting sessions');
        }

        if ($requested !== null && ! $requested->currency->equals($collection->currency)) {
            throw new InvalidCommand('amount', "must be in {$collection->currency->code}, the currency of the collection", 'sales.currency_mismatch');
        }

        $derived = PaymentCollectionStateMachine::derive($collection->amount, $this->sessions($collection->id));
        $remainingMinor = max(0, $collection->amount - $derived['committed']);
        $remaining = Money::ofMinor($remainingMinor, $collection->currency);
        $amount = $requested ?? $remaining;

        if (! $amount->isPositive() || $amount->amountMinor > $remainingMinor) {
            throw new PaymentExceedsBalance($collection->targetRef, $amount, $remaining);
        }

        return $amount;
    }

    public function openGatewaySession(PaymentCollectionRow $collection, AuthorizeGatewaySession $command, string $fingerprint): PaymentSessionRow
    {
        $amount = $this->resolveSessionAmount($collection, $command->amount);
        $id = Uuid7::generate();
        $now = PgTimestamp::now($this->clock);

        $this->db()->table($this->tables->table('payment_sessions'))->insert([
            'id' => $id,
            'collection_id' => $collection->id->value,
            'kind' => PaymentSessionKind::Gateway->value,
            'gateway' => $command->gateway,
            'stored_value_kind' => null,
            'stored_value_account_id' => null,
            'stored_value_hold_id' => null,
            'reference' => 'session:'.$id,
            'external_id' => null,
            'status' => PaymentSessionStatus::Authorizing->value,
            'currency' => $collection->currency->code,
            'amount_minor' => $amount->amountMinor,
            'authorized_minor' => 0,
            'captured_minor' => 0,
            'capture_closed' => false,
            'action_url' => null,
            'meta' => PaymentJournal::json($command->meta),
            'fingerprint' => $fingerprint,
            'attempts' => 1,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->getSession(new PaymentSessionId($id));
    }

    public function recordStoredValueSession(
        PaymentCollectionRow $collection,
        AddStoredValueSession $command,
        StoredValueHold $hold,
        ?StoredValueCapture $capture,
        string $fingerprint,
    ): PaymentSessionRow {
        $id = Uuid7::generate();
        $now = PgTimestamp::now($this->clock);
        $capturedMinor = $capture?->captured->amountMinor ?? 0;
        $status = $capture !== null ? PaymentSessionStatus::Captured : PaymentSessionStatus::Authorized;

        $this->db()->table($this->tables->table('payment_sessions'))->insert([
            'id' => $id,
            'collection_id' => $collection->id->value,
            'kind' => PaymentSessionKind::StoredValue->value,
            'gateway' => null,
            'stored_value_kind' => $command->storedValueKind->value,
            'stored_value_account_id' => $hold->accountId,
            'stored_value_hold_id' => $hold->holdId,
            'reference' => 'session:'.$id,
            'external_id' => $hold->holdId,
            'status' => $status->value,
            'currency' => $collection->currency->code,
            'amount_minor' => $command->amount->amountMinor,
            'authorized_minor' => $hold->amount->amountMinor,
            'captured_minor' => $capturedMinor,
            'capture_closed' => $capture !== null,
            'action_url' => null,
            'meta' => PaymentJournal::json($command->meta),
            'fingerprint' => $fingerprint,
            'attempts' => 1,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($capture !== null) {
            $captureId = Uuid7::generate();
            $this->db()->table($this->tables->table('payment_captures'))->insert([
                'id' => $captureId,
                'session_id' => $id,
                'collection_id' => $collection->id->value,
                'reference' => 'capture:'.$captureId,
                'external_id' => $capture->journalId,
                'status' => PaymentCaptureStatus::Succeeded->value,
                'currency' => $collection->currency->code,
                'amount_minor' => $capture->captured->amountMinor,
                'is_final_capture' => true,
                'meta' => PaymentJournal::json($command->meta),
                'fingerprint' => $fingerprint,
                'attempts' => 1,
                'claimed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->refreshCollection($collection);

        return $this->getSession(new PaymentSessionId($id));
    }

    public function openCapture(
        PaymentSessionRow $session,
        Money $amount,
        bool $isFinalCapture,
        CapturePaymentSession $command,
        string $fingerprint,
    ): PaymentCaptureRow {
        $id = Uuid7::generate();
        $now = PgTimestamp::now($this->clock);

        $this->db()->table($this->tables->table('payment_captures'))->insert([
            'id' => $id,
            'session_id' => $session->id->value,
            'collection_id' => $session->collection->value,
            'reference' => 'capture:'.$id,
            'external_id' => null,
            'status' => PaymentCaptureStatus::Initiating->value,
            'currency' => $session->currency->code,
            'amount_minor' => $amount->amountMinor,
            'is_final_capture' => $isFinalCapture,
            'meta' => PaymentJournal::json($command->meta),
            'fingerprint' => $fingerprint,
            'attempts' => 1,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->getCapture(new PaymentCaptureId($id));
    }

    public function recordStoredValueCapture(
        PaymentCollectionRow $collection,
        PaymentSessionRow $session,
        StoredValueCapture $capture,
        bool $isFinalCapture,
        CapturePaymentSession $command,
        string $fingerprint,
    ): PaymentSessionRow {
        $captureId = Uuid7::generate();
        $now = PgTimestamp::now($this->clock);
        $newCaptured = $session->captured + $capture->captured->amountMinor;
        $closed = $isFinalCapture || $newCaptured >= $session->authorized;
        $newStatus = $closed ? PaymentSessionStatus::Captured : PaymentSessionStatus::PartiallyCaptured;

        $this->db()->table($this->tables->table('payment_captures'))->insert([
            'id' => $captureId,
            'session_id' => $session->id->value,
            'collection_id' => $collection->id->value,
            'reference' => 'capture:'.$captureId,
            'external_id' => $capture->journalId,
            'status' => PaymentCaptureStatus::Succeeded->value,
            'currency' => $session->currency->code,
            'amount_minor' => $capture->captured->amountMinor,
            'is_final_capture' => $closed,
            'meta' => PaymentJournal::json($command->meta),
            'fingerprint' => $fingerprint,
            'attempts' => 1,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->db()->table($this->tables->table('payment_sessions'))->where('id', $session->id->value)->update([
            'captured_minor' => $newCaptured,
            'capture_closed' => $closed,
            'status' => $newStatus->value,
            'updated_at' => $now,
        ]);

        $this->refreshCollection($collection);

        return $this->getSession($session->id);
    }

    public function leaseSession(PaymentSessionRow $session): PaymentSessionRow
    {
        $now = PgTimestamp::now($this->clock);

        $this->db()->table($this->tables->table('payment_sessions'))->where('id', $session->id->value)->update([
            'attempts' => $session->attempts + 1,
            'claimed_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->getSession($session->id);
    }

    /**
     * Fenced session finish transaction (D33 / SAL-03).
     *
     * @param  Closure(PaymentCollectionRow, PaymentSessionRow): PaymentSessionRow  $write
     */
    public function finishSession(PaymentSessionRow $claimed, int $attempt, Closure $write): PaymentSessionRow
    {
        return $this->transactions->run(function () use ($claimed, $attempt, $write): PaymentSessionRow {
            $collection = $this->lock($claimed->collection);
            $current = $this->lockSession($claimed->id);

            if ($current->attempts !== $attempt) {
                return $current;
            }

            $written = $write($collection, $current);
            $this->refreshCollection($collection);

            return $written;
        });
    }

    /** @param  array<string, scalar|null>  $values */
    public function updateSession(PaymentSessionId $id, array $values): PaymentSessionRow
    {
        $this->db()->table($this->tables->table('payment_sessions'))
            ->where('id', $id->value)
            ->update($values + ['updated_at' => PgTimestamp::now($this->clock)]);

        return $this->getSession($id);
    }

    /**
     * @param  array<string, scalar>  $meta
     */
    public function insertAutoCaptureRow(
        PaymentCollectionRow $collection,
        PaymentSessionRow $session,
        int $capturedMinor,
        ?string $externalId,
        array $meta,
    ): void {
        $captureId = Uuid7::generate();
        $now = PgTimestamp::now($this->clock);

        $this->db()->table($this->tables->table('payment_captures'))->insert([
            'id' => $captureId,
            'session_id' => $session->id->value,
            'collection_id' => $collection->id->value,
            'reference' => 'capture:'.$captureId,
            'external_id' => $externalId,
            'status' => PaymentCaptureStatus::Succeeded->value,
            'currency' => $session->currency->code,
            'amount_minor' => $capturedMinor,
            'is_final_capture' => true,
            'meta' => PaymentJournal::json($meta),
            'fingerprint' => hash('sha256', $session->reference.':auto_capture'),
            'attempts' => 1,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Fenced capture finish transaction (D33).
     *
     * @param  Closure(PaymentCollectionRow, PaymentSessionRow, PaymentCaptureRow): void  $write
     */
    public function finishCapture(PaymentCaptureRow $claimed, int $attempt, Closure $write): PaymentCollectionRow
    {
        return $this->transactions->run(function () use ($claimed, $attempt, $write): PaymentCollectionRow {
            $collection = $this->lock($claimed->collection);
            $session = $this->lockSession($claimed->session);
            $row = $this->db()->table($this->tables->table('payment_captures'))
                ->where('id', $claimed->id->value)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return $collection;
            }

            $current = PaymentCaptureRow::fromRow($row);

            if ($current->attempts !== $attempt) {
                return $collection;
            }

            $write($collection, $session, $current);

            return $this->refreshCollection($collection);
        });
    }

    /** @param  array<string, scalar|null>  $values */
    public function updateCapture(PaymentCaptureId $id, array $values): void
    {
        $this->db()->table($this->tables->table('payment_captures'))
            ->where('id', $id->value)
            ->update($values + ['updated_at' => PgTimestamp::now($this->clock)]);
    }

    public function refreshCollection(PaymentCollectionRow $collection, bool $cancelled = false): PaymentCollectionRow
    {
        $derived = PaymentCollectionStateMachine::derive(
            $collection->amount,
            $this->sessions($collection->id),
            $cancelled || $collection->status === PaymentCollectionStatus::Cancelled,
        );

        $changed = $derived['status'] !== $collection->status
            || $derived['authorized'] !== $collection->authorized
            || $derived['captured'] !== $collection->captured;

        if (! $changed) {
            return $collection;
        }

        $now = PgTimestamp::now($this->clock);

        $this->db()->table($this->tables->table('payment_collections'))
            ->where('id', $collection->id->value)
            ->update([
                'status' => $derived['status']->value,
                'authorized_minor' => $derived['authorized'],
                'captured_minor' => $derived['captured'],
                'updated_at' => $now,
            ]);

        if ($derived['status'] !== $collection->status) {
            $this->events->dispatch(new PaymentCollectionStatusChanged(
                collection: $collection->id,
                targetRef: $collection->targetRef,
                order: $collection->order,
                from: $collection->status,
                to: $derived['status'],
            ));
        }

        return $this->get($collection->id);
    }

    private function assemble(PaymentCollectionRow $collection): PaymentCollectionView
    {
        $sessionViews = [];

        foreach ($this->sessions($collection->id) as $session) {
            $captureViews = array_map(
                static fn (PaymentCaptureRow $capture): PaymentCaptureView => $capture->view(),
                $this->captures($session->id),
            );
            $sessionViews[] = $session->view($captureViews);
        }

        return $collection->view($sessionViews);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}
