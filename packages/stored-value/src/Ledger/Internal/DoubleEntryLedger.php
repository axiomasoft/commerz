<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Ledger\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\StoredValue\Data\CaptureStoredValue;
use Commerz\Ports\StoredValue\Data\HoldStoredValue;
use Commerz\Ports\StoredValue\Data\RefundToStoredValue;
use Commerz\Ports\StoredValue\Data\ReleaseStoredValue;
use Commerz\Ports\StoredValue\Data\StoredValueCapture;
use Commerz\Ports\StoredValue\Data\StoredValueCredit;
use Commerz\Ports\StoredValue\Data\StoredValueHold;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\Ports\StoredValue\Exceptions\InsufficientStoredValue;
use Commerz\Ports\StoredValue\Exceptions\StoredValueNotFound;
use Commerz\Ports\StoredValue\Exceptions\StoredValueUnavailable;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\StoredValue\Configuration\StoredValueOptions;
use Commerz\StoredValue\Ledger\Contracts\StoredValueLedger;
use Commerz\StoredValue\Ledger\Data\AccountId;
use Commerz\StoredValue\Ledger\Data\AccountSnapshot;
use Commerz\StoredValue\Ledger\Data\LedgerEntry;
use Commerz\StoredValue\Ledger\Enums\AccountStatus;
use Commerz\StoredValue\Ledger\Enums\LedgerDirection;
use Commerz\StoredValue\Ledger\Enums\LedgerEntrySide;
use Commerz\StoredValue\Ledger\Enums\LedgerEntryType;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Override;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * Atomic, immutable double-entry ledger engine for stored-value accounts.
 *
 * Enforces INV-SV-001 (`balance_minor - held_minor >= 0`) via row locking (`FOR UPDATE`), atomic conditional SQL
 * `UPDATE` statements, and database `CHECK` constraints. Every balance or hold mutation posts a balanced debit/credit
 * pair sharing one `journal_id`.
 */
#[Internal]
final readonly class DoubleEntryLedger implements StoredValueLedger
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $commerz,
        private StoredValueOptions $options,
        private TableNames $tables,
        private Transactions $transactions,
        private Clock $clock,
    ) {}

    /** @return list<LedgerEntry> */
    #[Override]
    public function entriesForAccount(AccountId $accountId, ?LedgerEntrySide $side = null, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidCommand('limit', 'limit must be between 1 and 1000', 'common.invalid_page_size');
        }

        $query = $this->db()
            ->table($this->tables->table('stored_value_ledger'))
            ->where('account_id', $accountId->value)
            ->orderBy('id')
            ->limit($limit);

        if ($side !== null) {
            $query->where('entry_side', $side->value);
        }

        return array_values(array_map(self::hydrateEntry(...), $query->get()->all()));
    }

    /** @return list<LedgerEntry> */
    #[Override]
    public function journalEntries(string $journalId): array
    {
        $rows = $this->db()
            ->table($this->tables->table('stored_value_ledger'))
            ->where('journal_id', $journalId)
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_map(self::hydrateEntry(...), $rows));
    }

    #[Override]
    public function isBalanced(AccountId $accountId): bool
    {
        $db = $this->db();
        $account = $db->table($this->tables->table('stored_value_accounts'))
            ->where('id', $accountId->value)
            ->first(['balance_minor', 'held_minor']);

        if ($account === null) {
            return false;
        }

        $balanceMinor = Rows::int($account, 'balance_minor');
        $heldMinor = Rows::int($account, 'held_minor');

        if ($balanceMinor < 0 || $heldMinor < 0 || $balanceMinor - $heldMinor < 0) {
            return false;
        }

        $rows = $db->table($this->tables->table('stored_value_ledger'))
            ->where('account_id', $accountId->value)
            ->get(['journal_id', 'direction', 'entry_side', 'amount_minor', 'balance_delta_minor', 'held_delta_minor'])
            ->all();

        $journalTotals = [];
        $recomputedBalance = 0;
        $recomputedHeld = 0;

        foreach ($rows as $row) {
            $jid = Rows::string($row, 'journal_id');
            $amount = Rows::int($row, 'amount_minor');
            $dir = Rows::string($row, 'direction');
            $journalTotals[$jid] = ($journalTotals[$jid] ?? 0) + ($dir === LedgerDirection::Debit->value ? $amount : -$amount);

            if (Rows::string($row, 'entry_side') === LedgerEntrySide::Account->value) {
                $recomputedBalance += Rows::int($row, 'balance_delta_minor');
                $recomputedHeld += Rows::int($row, 'held_delta_minor');
            }
        }

        foreach ($journalTotals as $net) {
            if ($net !== 0) {
                return false;
            }
        }

        return $recomputedBalance === $balanceMinor && $recomputedHeld === $heldMinor;
    }

    public function findAccount(AccountId $accountId): ?AccountSnapshot
    {
        $row = $this->db()
            ->table($this->tables->table('stored_value_accounts'))
            ->where('id', $accountId->value)
            ->first();

        return $row === null ? null : self::hydrateAccount($row);
    }

    public function findAccountByCodeHash(string $codeHash): ?AccountSnapshot
    {
        $row = $this->db()
            ->table($this->tables->table('stored_value_accounts'))
            ->where('kind', StoredValueKind::GiftCard->value)
            ->where('code_hash', $codeHash)
            ->first();

        return $row === null ? null : self::hydrateAccount($row);
    }

    public function findWalletByCustomer(CustomerRef $customer, Currency $currency): ?AccountSnapshot
    {
        $row = $this->db()
            ->table($this->tables->table('stored_value_accounts'))
            ->where('kind', StoredValueKind::Wallet->value)
            ->where('customer_ref', (string) $customer)
            ->where('currency', $currency->code)
            ->first();

        return $row === null ? null : self::hydrateAccount($row);
    }

    /**
     * Creates a new stored-value account and, when `$initialBalance->isPositive()`, posts the opening `issue` journal.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createAccount(
        StoredValueKind $kind,
        Money $initialBalance,
        ?string $codeHash = null,
        ?string $codeLast4 = null,
        ?CustomerRef $customer = null,
        ?DateTimeImmutable $expiresAt = null,
        array $metadata = [],
        ?string $reference = null,
        ?string $idempotencyKey = null,
        ?string $actorRef = null,
        ?string $reason = null,
    ): AccountSnapshot {
        if ($initialBalance->isNegative()) {
            throw new InvalidCommand('initialBalance', 'initial balance cannot be negative', 'stored_value.invalid_amount');
        }

        return $this->transactions->run(function () use (
            $kind,
            $initialBalance,
            $codeHash,
            $codeLast4,
            $customer,
            $expiresAt,
            $metadata,
            $reference,
            $idempotencyKey,
            $actorRef,
            $reason,
        ): AccountSnapshot {
            $db = $this->db();
            $accountsTable = $this->tables->table('stored_value_accounts');
            $nowInstant = PgTimestamp::current($this->clock);
            $now = PgTimestamp::format($nowInstant);
            $id = Uuid7::generate();

            $db->table($accountsTable)->insert([
                'id' => $id,
                'kind' => $kind->value,
                'status' => AccountStatus::Active->value,
                'currency' => $initialBalance->currency->code,
                'code_hash' => $codeHash,
                'code_last4' => $codeLast4,
                'customer_ref' => $customer !== null ? (string) $customer : null,
                'initial_minor' => $initialBalance->amountMinor,
                'balance_minor' => $initialBalance->amountMinor,
                'held_minor' => 0,
                'version' => 0,
                'expires_at' => $expiresAt !== null ? PgTimestamp::format($expiresAt) : null,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($initialBalance->isPositive()) {
                $this->postJournal(
                    accountId: $id,
                    holdId: null,
                    entryType: LedgerEntryType::Issue,
                    accountDirection: LedgerDirection::Credit,
                    counterpartAccount: 'asset:issuance',
                    currency: $initialBalance->currency->code,
                    amountMinor: $initialBalance->amountMinor,
                    balanceDeltaMinor: $initialBalance->amountMinor,
                    heldDeltaMinor: 0,
                    balanceAfterMinor: $initialBalance->amountMinor,
                    heldAfterMinor: 0,
                    reference: $reference,
                    idempotencyKey: $idempotencyKey,
                    actorRef: $actorRef,
                    reason: $reason,
                    occurredAt: $now,
                );
            }

            $created = $db->table($accountsTable)->where('id', $id)->first()
                ?? throw new RuntimeException("Stored-value account '{$id}' disappeared inside transaction.");

            return self::hydrateAccount($created);
        });
    }

    /**
     * Gets or creates a customer wallet in `$currency` safely under concurrency.
     */
    public function getOrCreateWallet(CustomerRef $customer, Currency $currency): AccountSnapshot
    {
        $existing = $this->findWalletByCustomer($customer, $currency);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->createAccount(
                kind: StoredValueKind::Wallet,
                initialBalance: Money::zero($currency),
                customer: $customer,
            );
        } catch (UniqueConstraintViolationException) {
            return $this->findWalletByCustomer($customer, $currency)
                ?? throw new RuntimeException("Wallet for '{$customer}' in {$currency->code} conflicted but could not be read.");
        }
    }

    /**
     * Credits an existing account (entry type `issue` or `refund_credit`), idempotent per `$idempotencyKey`.
     */
    public function creditAccount(
        AccountId $accountId,
        Money $amount,
        LedgerEntryType $entryType,
        string $reference,
        string $idempotencyKey,
        ?string $actorRef = null,
        ?string $reason = null,
    ): StoredValueCredit {
        if (! in_array($entryType, [LedgerEntryType::Issue, LedgerEntryType::RefundCredit], true)) {
            throw new InvalidCommand('entryType', 'creditAccount accepts only issue or refund_credit', 'stored_value.invalid_amount');
        }

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'credit amount must be positive', 'stored_value.invalid_amount');
        }

        return $this->transactions->run(function () use (
            $accountId,
            $amount,
            $entryType,
            $reference,
            $idempotencyKey,
            $actorRef,
            $reason,
        ): StoredValueCredit {
            $db = $this->db();
            $ledgerTable = $this->tables->table('stored_value_ledger');
            $accountsTable = $this->tables->table('stored_value_accounts');

            $existing = $db->table($ledgerTable)
                ->where('entry_type', $entryType->value)
                ->where('entry_side', LedgerEntrySide::Account->value)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                $existingAccount = Rows::string($existing, 'account_id');
                $existingAmount = Rows::int($existing, 'amount_minor');
                $existingCurrency = Rows::string($existing, 'currency');

                if ($existingAccount !== $accountId->value
                    || $existingAmount !== $amount->amountMinor
                    || $existingCurrency !== $amount->currency->code) {
                    throw new IdempotencyConflict('stored_value.'.$entryType->value, $idempotencyKey);
                }

                return new StoredValueCredit(
                    accountId: $existingAccount,
                    amount: $amount,
                    balanceAfter: Money::ofMinor(Rows::int($existing, 'balance_after_minor'), $existingCurrency),
                    journalId: Rows::string($existing, 'journal_id'),
                );
            }

            $row = $db->table($accountsTable)
                ->where('id', $accountId->value)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new StoredValueNotFound('stored_value_account', $accountId->value);
            }

            $account = self::hydrateAccount($row);

            if (! $account->currency->equals($amount->currency)) {
                throw new StoredValueUnavailable(
                    $accountId->value,
                    'currency_mismatch',
                    "Account '{$accountId->value}' is in {$account->currency->code}, cannot credit {$amount->currency->code}.",
                );
            }

            if ($account->status === AccountStatus::Voided) {
                throw new StoredValueUnavailable($accountId->value, 'voided');
            }

            if ($account->status === AccountStatus::Frozen) {
                throw new StoredValueUnavailable($accountId->value, 'frozen');
            }

            $now = PgTimestamp::format(PgTimestamp::current($this->clock));
            $wrappedAccounts = $this->wrap($accountsTable);

            $updated = $db->update(
                "update {$wrappedAccounts} set balance_minor = balance_minor + ?, version = version + 1, updated_at = ?"
                ." where id = ? and status in ('active', 'expired')",
                [$amount->amountMinor, $now, $accountId->value],
            );

            if ($updated !== 1) {
                throw new StoredValueUnavailable($accountId->value, $account->status->value);
            }

            $after = $db->table($accountsTable)->where('id', $accountId->value)->first(['balance_minor', 'held_minor'])
                ?? throw new RuntimeException("Account '{$accountId->value}' disappeared after update.");
            $balanceAfter = Rows::int($after, 'balance_minor');
            $heldAfter = Rows::int($after, 'held_minor');

            $counterpart = $entryType === LedgerEntryType::RefundCredit
                ? 'clearing:refund_credit'
                : 'asset:issuance';

            $journalId = $this->postJournal(
                accountId: $accountId->value,
                holdId: null,
                entryType: $entryType,
                accountDirection: LedgerDirection::Credit,
                counterpartAccount: $counterpart,
                currency: $amount->currency->code,
                amountMinor: $amount->amountMinor,
                balanceDeltaMinor: $amount->amountMinor,
                heldDeltaMinor: 0,
                balanceAfterMinor: $balanceAfter,
                heldAfterMinor: $heldAfter,
                reference: $reference,
                idempotencyKey: $idempotencyKey,
                actorRef: $actorRef,
                reason: $reason,
                occurredAt: $now,
            );

            return new StoredValueCredit(
                accountId: $accountId->value,
                amount: $amount,
                balanceAfter: Money::ofMinor($balanceAfter, $amount->currency),
                journalId: $journalId,
            );
        });
    }

    /**
     * Atomically holds funds on an active account, enforcing INV-SV-001 (`balance_minor - held_minor >= 0`).
     */
    public function hold(HoldStoredValue $command): StoredValueHold
    {
        return $this->transactions->run(function () use ($command): StoredValueHold {
            $db = $this->db();
            $holdsTable = $this->tables->table('stored_value_holds');
            $accountsTable = $this->tables->table('stored_value_accounts');

            $existing = $db->table($holdsTable)
                ->where('idempotency_key', $command->idempotencyKey)
                ->first();

            if ($existing !== null) {
                $existingHold = self::hydrateHold($existing);

                if ($existingHold->accountId !== $command->accountId
                    || $existingHold->orderRef !== $command->orderRef
                    || ! $existingHold->amount->equals($command->amount)) {
                    throw new IdempotencyConflict('stored_value.hold', $command->idempotencyKey);
                }

                return $existingHold;
            }

            $accountRow = $db->table($accountsTable)
                ->where('id', $command->accountId)
                ->lockForUpdate()
                ->first();

            if ($accountRow === null) {
                throw new StoredValueNotFound('stored_value_account', $command->accountId);
            }

            $account = self::hydrateAccount($accountRow);
            $nowInstant = PgTimestamp::current($this->clock);
            $now = PgTimestamp::format($nowInstant);

            if (! $account->currency->equals($command->amount->currency)) {
                throw new StoredValueUnavailable(
                    $command->accountId,
                    'currency_mismatch',
                    "Account '{$command->accountId}' is in {$account->currency->code}, hold requested {$command->amount->currency->code}.",
                );
            }

            if ($account->status !== AccountStatus::Active) {
                throw new StoredValueUnavailable($command->accountId, $account->status->value);
            }

            if ($account->isExpiredAt($nowInstant)) {
                throw new StoredValueUnavailable($command->accountId, AccountStatus::Expired->value);
            }

            $wrappedAccounts = $this->wrap($accountsTable);

            // Atomic conditional UPDATE enforcing INV-SV-001 (balance_minor - held_minor >= requested).
            $updated = $db->update(
                "update {$wrappedAccounts}"
                .' set held_minor = held_minor + ?, version = version + 1, updated_at = ?'
                ." where id = ? and status = 'active' and (expires_at is null or expires_at > ?) and (balance_minor - held_minor) >= ?",
                [
                    $command->amount->amountMinor,
                    $now,
                    $command->accountId,
                    $now,
                    $command->amount->amountMinor,
                ],
            );

            if ($updated !== 1) {
                $current = $db->table($accountsTable)->where('id', $command->accountId)->first()
                    ?? throw new StoredValueNotFound('stored_value_account', $command->accountId);
                $snapshot = self::hydrateAccount($current);

                if ($snapshot->status !== AccountStatus::Active) {
                    throw new StoredValueUnavailable($command->accountId, $snapshot->status->value);
                }

                if ($snapshot->isExpiredAt($nowInstant)) {
                    throw new StoredValueUnavailable($command->accountId, AccountStatus::Expired->value);
                }

                throw new InsufficientStoredValue(
                    accountId: $command->accountId,
                    requestedMinor: $command->amount->amountMinor,
                    availableMinor: $snapshot->available->amountMinor,
                    currency: $snapshot->currency->code,
                );
            }

            $after = $db->table($accountsTable)->where('id', $command->accountId)->first(['balance_minor', 'held_minor'])
                ?? throw new RuntimeException("Account '{$command->accountId}' disappeared after hold update.");
            $balanceAfter = Rows::int($after, 'balance_minor');
            $heldAfter = Rows::int($after, 'held_minor');

            $expiresAt = $command->expiresAt;

            if ($expiresAt === null && $this->options->defaultHoldTtlSeconds !== null) {
                $expiresAt = $nowInstant->add(new DateInterval('PT'.$this->options->defaultHoldTtlSeconds.'S'));
            }

            $holdId = Uuid7::generate();

            $db->table($holdsTable)->insert([
                'id' => $holdId,
                'account_id' => $command->accountId,
                'order_ref' => $command->orderRef,
                'idempotency_key' => $command->idempotencyKey,
                'currency' => $command->amount->currency->code,
                'amount_minor' => $command->amount->amountMinor,
                'captured_minor' => 0,
                'released_minor' => 0,
                'state' => 'held',
                'expires_at' => $expiresAt !== null ? PgTimestamp::format($expiresAt) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->postJournal(
                accountId: $command->accountId,
                holdId: $holdId,
                entryType: LedgerEntryType::Hold,
                accountDirection: LedgerDirection::Debit,
                counterpartAccount: "liability:held:{$command->accountId}",
                currency: $command->amount->currency->code,
                amountMinor: $command->amount->amountMinor,
                balanceDeltaMinor: 0,
                heldDeltaMinor: $command->amount->amountMinor,
                balanceAfterMinor: $balanceAfter,
                heldAfterMinor: $heldAfter,
                reference: $command->orderRef,
                idempotencyKey: $command->idempotencyKey,
                actorRef: $command->actorRef,
                reason: $command->reason,
                occurredAt: $now,
            );

            return new StoredValueHold(
                holdId: $holdId,
                accountId: $command->accountId,
                orderRef: $command->orderRef,
                amount: $command->amount,
                captured: Money::zero($command->amount->currency),
                released: Money::zero($command->amount->currency),
                state: 'held',
                expiresAt: $expiresAt,
            );
        });
    }

    /**
     * Captures all or part of an active hold into a final debit. Idempotent per `$command->idempotencyKey`.
     */
    public function capture(CaptureStoredValue $command): StoredValueCapture
    {
        return $this->transactions->run(function () use ($command): StoredValueCapture {
            $db = $this->db();
            $ledgerTable = $this->tables->table('stored_value_ledger');
            $holdsTable = $this->tables->table('stored_value_holds');
            $accountsTable = $this->tables->table('stored_value_accounts');

            $existing = $db->table($ledgerTable)
                ->where('entry_type', LedgerEntryType::Capture->value)
                ->where('entry_side', LedgerEntrySide::Account->value)
                ->where('idempotency_key', $command->idempotencyKey)
                ->first();

            if ($existing !== null) {
                $existingHoldId = Rows::nullableString($existing, 'hold_id');
                $existingAmount = Rows::int($existing, 'amount_minor');
                $existingCurrency = Rows::string($existing, 'currency');

                if ($existingHoldId !== $command->holdId
                    || ($command->amount !== null && ($existingAmount !== $command->amount->amountMinor || $existingCurrency !== $command->amount->currency->code))) {
                    throw new IdempotencyConflict('stored_value.capture', $command->idempotencyKey);
                }

                return new StoredValueCapture(
                    holdId: $command->holdId,
                    accountId: Rows::string($existing, 'account_id'),
                    captured: Money::ofMinor($existingAmount, $existingCurrency),
                    balanceAfter: Money::ofMinor(Rows::int($existing, 'balance_after_minor'), $existingCurrency),
                    heldAfter: Money::ofMinor(Rows::int($existing, 'held_after_minor'), $existingCurrency),
                    journalId: Rows::string($existing, 'journal_id'),
                );
            }

            $holdRow = $db->table($holdsTable)
                ->where('id', $command->holdId)
                ->lockForUpdate()
                ->first();

            if ($holdRow === null) {
                throw new StoredValueNotFound('stored_value_hold', $command->holdId);
            }

            $hold = self::hydrateHold($holdRow);
            $remaining = $hold->remaining();

            if ($hold->state !== 'held' || ! $remaining->isPositive()) {
                throw new StoredValueUnavailable($hold->accountId, 'hold_closed', "Hold '{$hold->holdId}' is '{$hold->state}' and cannot be captured.");
            }

            $captureAmount = $command->amount ?? $remaining;

            if (! $captureAmount->currency->equals($hold->amount->currency)) {
                throw new StoredValueUnavailable(
                    $hold->accountId,
                    'currency_mismatch',
                    "Hold '{$hold->holdId}' is in {$hold->amount->currency->code}, capture requested {$captureAmount->currency->code}.",
                );
            }

            if ($captureAmount->amountMinor > $remaining->amountMinor) {
                throw new InsufficientStoredValue(
                    accountId: $hold->accountId,
                    requestedMinor: $captureAmount->amountMinor,
                    availableMinor: $remaining->amountMinor,
                    currency: $hold->amount->currency->code,
                );
            }

            $db->table($accountsTable)
                ->where('id', $hold->accountId)
                ->lockForUpdate()
                ->first(['id'])
                ?? throw new StoredValueNotFound('stored_value_account', $hold->accountId);

            $now = PgTimestamp::format(PgTimestamp::current($this->clock));
            $wrappedAccounts = $this->wrap($accountsTable);

            $updatedAccount = $db->update(
                "update {$wrappedAccounts}"
                .' set balance_minor = balance_minor - ?, held_minor = held_minor - ?, version = version + 1, updated_at = ?'
                .' where id = ? and held_minor >= ? and balance_minor >= ? and (balance_minor - held_minor) >= 0',
                [
                    $captureAmount->amountMinor,
                    $captureAmount->amountMinor,
                    $now,
                    $hold->accountId,
                    $captureAmount->amountMinor,
                    $captureAmount->amountMinor,
                ],
            );

            if ($updatedAccount !== 1) {
                throw new InsufficientStoredValue(
                    accountId: $hold->accountId,
                    requestedMinor: $captureAmount->amountMinor,
                    availableMinor: $remaining->amountMinor,
                    currency: $hold->amount->currency->code,
                );
            }

            $newCaptured = $hold->captured->amountMinor + $captureAmount->amountMinor;
            $newState = $newCaptured + $hold->released->amountMinor === $hold->amount->amountMinor ? 'captured' : 'held';

            $db->table($holdsTable)
                ->where('id', $hold->holdId)
                ->update([
                    'captured_minor' => $newCaptured,
                    'state' => $newState,
                    'updated_at' => $now,
                ]);

            $after = $db->table($accountsTable)->where('id', $hold->accountId)->first(['balance_minor', 'held_minor'])
                ?? throw new RuntimeException("Account '{$hold->accountId}' disappeared after capture.");
            $balanceAfter = Rows::int($after, 'balance_minor');
            $heldAfter = Rows::int($after, 'held_minor');

            $journalId = $this->postJournal(
                accountId: $hold->accountId,
                holdId: $hold->holdId,
                entryType: LedgerEntryType::Capture,
                accountDirection: LedgerDirection::Debit,
                counterpartAccount: 'clearing:order_settlement',
                currency: $captureAmount->currency->code,
                amountMinor: $captureAmount->amountMinor,
                balanceDeltaMinor: -$captureAmount->amountMinor,
                heldDeltaMinor: -$captureAmount->amountMinor,
                balanceAfterMinor: $balanceAfter,
                heldAfterMinor: $heldAfter,
                reference: $command->reference,
                idempotencyKey: $command->idempotencyKey,
                actorRef: $command->actorRef,
                reason: $command->reason,
                occurredAt: $now,
            );

            return new StoredValueCapture(
                holdId: $hold->holdId,
                accountId: $hold->accountId,
                captured: $captureAmount,
                balanceAfter: Money::ofMinor($balanceAfter, $captureAmount->currency),
                heldAfter: Money::ofMinor($heldAfter, $captureAmount->currency),
                journalId: $journalId,
            );
        });
    }

    /**
     * Releases all or part of an active hold back to available balance. Idempotent per `$command->idempotencyKey`.
     */
    public function release(ReleaseStoredValue $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $db = $this->db();
            $ledgerTable = $this->tables->table('stored_value_ledger');
            $holdsTable = $this->tables->table('stored_value_holds');
            $accountsTable = $this->tables->table('stored_value_accounts');

            $existing = $db->table($ledgerTable)
                ->where('entry_type', LedgerEntryType::Release->value)
                ->where('entry_side', LedgerEntrySide::Account->value)
                ->where('idempotency_key', $command->idempotencyKey)
                ->first();

            if ($existing !== null) {
                $existingHoldId = Rows::nullableString($existing, 'hold_id');
                $existingAmount = Rows::int($existing, 'amount_minor');

                if ($existingHoldId !== $command->holdId
                    || ($command->amount !== null && $existingAmount !== $command->amount->amountMinor)) {
                    throw new IdempotencyConflict('stored_value.release', $command->idempotencyKey);
                }

                return;
            }

            $holdRow = $db->table($holdsTable)
                ->where('id', $command->holdId)
                ->lockForUpdate()
                ->first();

            if ($holdRow === null) {
                throw new StoredValueNotFound('stored_value_hold', $command->holdId);
            }

            $hold = self::hydrateHold($holdRow);
            $remaining = $hold->remaining();

            if ($hold->state === 'released' || $hold->state === 'expired') {
                return;
            }

            if ($hold->state !== 'held' || ! $remaining->isPositive()) {
                throw new StoredValueUnavailable($hold->accountId, 'hold_closed', "Hold '{$hold->holdId}' is '{$hold->state}' and cannot be released.");
            }

            $releaseAmount = $command->amount ?? $remaining;

            if (! $releaseAmount->currency->equals($hold->amount->currency)) {
                throw new StoredValueUnavailable(
                    $hold->accountId,
                    'currency_mismatch',
                    "Hold '{$hold->holdId}' is in {$hold->amount->currency->code}, release requested {$releaseAmount->currency->code}.",
                );
            }

            if ($releaseAmount->amountMinor > $remaining->amountMinor) {
                throw new InvalidCommand('amount', 'release amount exceeds remaining held amount', 'stored_value.invalid_amount');
            }

            $db->table($accountsTable)
                ->where('id', $hold->accountId)
                ->lockForUpdate()
                ->first(['id'])
                ?? throw new StoredValueNotFound('stored_value_account', $hold->accountId);

            $now = PgTimestamp::format(PgTimestamp::current($this->clock));
            $wrappedAccounts = $this->wrap($accountsTable);

            $updatedAccount = $db->update(
                "update {$wrappedAccounts} set held_minor = held_minor - ?, version = version + 1, updated_at = ?"
                .' where id = ? and held_minor >= ?',
                [$releaseAmount->amountMinor, $now, $hold->accountId, $releaseAmount->amountMinor],
            );

            if ($updatedAccount !== 1) {
                throw new RuntimeException("Held balance underflow while releasing hold '{$hold->holdId}'.");
            }

            $newReleased = $hold->released->amountMinor + $releaseAmount->amountMinor;
            $newState = $hold->captured->amountMinor + $newReleased === $hold->amount->amountMinor
                ? ($hold->captured->amountMinor > 0 ? 'captured' : 'released')
                : 'held';

            $db->table($holdsTable)
                ->where('id', $hold->holdId)
                ->update([
                    'released_minor' => $newReleased,
                    'state' => $newState,
                    'updated_at' => $now,
                ]);

            $after = $db->table($accountsTable)->where('id', $hold->accountId)->first(['balance_minor', 'held_minor'])
                ?? throw new RuntimeException("Account '{$hold->accountId}' disappeared after release.");
            $balanceAfter = Rows::int($after, 'balance_minor');
            $heldAfter = Rows::int($after, 'held_minor');

            $this->postJournal(
                accountId: $hold->accountId,
                holdId: $hold->holdId,
                entryType: LedgerEntryType::Release,
                accountDirection: LedgerDirection::Credit,
                counterpartAccount: "liability:held:{$hold->accountId}",
                currency: $releaseAmount->currency->code,
                amountMinor: $releaseAmount->amountMinor,
                balanceDeltaMinor: 0,
                heldDeltaMinor: -$releaseAmount->amountMinor,
                balanceAfterMinor: $balanceAfter,
                heldAfterMinor: $heldAfter,
                reference: $hold->orderRef,
                idempotencyKey: $command->idempotencyKey,
                actorRef: $command->actorRef,
                reason: $command->reason,
                occurredAt: $now,
            );
        });
    }

    /**
     * Expires an account's unheld balance when `expires_at <= $asOf`, posting an `expire` breakage journal while
     * preserving INV-SV-001 (`balance_minor - held_minor >= 0`).
     */
    public function expireAccount(AccountId $accountId, ?DateTimeImmutable $asOf = null, ?string $actorRef = null): bool
    {
        return $this->transactions->run(function () use ($accountId, $asOf, $actorRef): bool {
            $db = $this->db();
            $accountsTable = $this->tables->table('stored_value_accounts');
            $nowInstant = $asOf ?? PgTimestamp::current($this->clock);
            $now = PgTimestamp::format($nowInstant);

            $row = $db->table($accountsTable)
                ->where('id', $accountId->value)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return false;
            }

            $account = self::hydrateAccount($row);

            if ($account->status === AccountStatus::Expired || $account->status === AccountStatus::Voided) {
                return false;
            }

            if ($account->expiresAt === null || $account->expiresAt > $nowInstant) {
                return false;
            }

            $expirableMinor = $account->available->amountMinor;
            $wrappedAccounts = $this->wrap($accountsTable);

            if ($expirableMinor > 0) {
                $updated = $db->update(
                    "update {$wrappedAccounts}"
                    ." set balance_minor = balance_minor - ?, status = case when held_minor = 0 then 'expired' else status end,"
                    .' version = version + 1, updated_at = ?'
                    .' where id = ? and (balance_minor - held_minor) >= ?',
                    [$expirableMinor, $now, $accountId->value, $expirableMinor],
                );

                if ($updated !== 1) {
                    return false;
                }

                $after = $db->table($accountsTable)->where('id', $accountId->value)->first(['balance_minor', 'held_minor'])
                    ?? throw new RuntimeException("Account '{$accountId->value}' disappeared during expiry.");

                $this->postJournal(
                    accountId: $accountId->value,
                    holdId: null,
                    entryType: LedgerEntryType::Expire,
                    accountDirection: LedgerDirection::Debit,
                    counterpartAccount: 'revenue:breakage',
                    currency: $account->currency->code,
                    amountMinor: $expirableMinor,
                    balanceDeltaMinor: -$expirableMinor,
                    heldDeltaMinor: 0,
                    balanceAfterMinor: Rows::int($after, 'balance_minor'),
                    heldAfterMinor: Rows::int($after, 'held_minor'),
                    reference: "expire:{$accountId->value}",
                    idempotencyKey: "expire:{$accountId->value}:{$account->version}",
                    actorRef: $actorRef,
                    reason: 'expired',
                    occurredAt: $now,
                );

                return true;
            }

            if ($account->held->isZero()) {
                $db->table($accountsTable)->where('id', $accountId->value)->update([
                    'status' => AccountStatus::Expired->value,
                    'version' => $account->version + 1,
                    'updated_at' => $now,
                ]);

                return true;
            }

            return false;
        });
    }

    public function expireDueAccounts(?DateTimeImmutable $asOf = null, ?string $actorRef = null): int
    {
        $nowInstant = $asOf ?? PgTimestamp::current($this->clock);
        $now = PgTimestamp::format($nowInstant);

        $ids = $this->db()
            ->table($this->tables->table('stored_value_accounts'))
            ->whereIn('status', [AccountStatus::Active->value, AccountStatus::Frozen->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $expired = 0;

        foreach ($ids as $id) {
            if (is_string($id) && $this->expireAccount(AccountId::fromString($id), $nowInstant, $actorRef)) {
                $expired++;
            }
        }

        return $expired;
    }

    public function transitionStatus(AccountId $accountId, AccountStatus $target, ?string $reason = null): AccountSnapshot
    {
        return $this->transactions->run(function () use ($accountId, $target, $reason): AccountSnapshot {
            $db = $this->db();
            $accountsTable = $this->tables->table('stored_value_accounts');

            $row = $db->table($accountsTable)
                ->where('id', $accountId->value)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new StoredValueNotFound('stored_value_account', $accountId->value);
            }

            $account = self::hydrateAccount($row);

            if ($account->status === $target) {
                return $account;
            }

            if ($account->status === AccountStatus::Voided || $account->status === AccountStatus::Expired) {
                throw new StoredValueUnavailable(
                    $accountId->value,
                    $account->status->value,
                    "Account '{$accountId->value}' in status '{$account->status->value}' cannot transition to '{$target->value}'.",
                );
            }

            if ($target === AccountStatus::Voided && ! $account->held->isZero()) {
                throw new StoredValueUnavailable(
                    $accountId->value,
                    'active_holds',
                    "Account '{$accountId->value}' cannot be voided while holds remain open.",
                );
            }

            $now = PgTimestamp::format(PgTimestamp::current($this->clock));

            if ($target === AccountStatus::Voided && $account->balance->isPositive()) {
                $amountMinor = $account->balance->amountMinor;

                $db->table($accountsTable)->where('id', $accountId->value)->update([
                    'status' => AccountStatus::Voided->value,
                    'balance_minor' => 0,
                    'version' => $account->version + 1,
                    'updated_at' => $now,
                ]);

                $this->postJournal(
                    accountId: $accountId->value,
                    holdId: null,
                    entryType: LedgerEntryType::Expire,
                    accountDirection: LedgerDirection::Debit,
                    counterpartAccount: 'revenue:breakage',
                    currency: $account->currency->code,
                    amountMinor: $amountMinor,
                    balanceDeltaMinor: -$amountMinor,
                    heldDeltaMinor: 0,
                    balanceAfterMinor: 0,
                    heldAfterMinor: 0,
                    reference: "void:{$accountId->value}",
                    idempotencyKey: "void:{$accountId->value}:{$account->version}",
                    actorRef: null,
                    reason: $reason ?? 'voided',
                    occurredAt: $now,
                );
            } else {
                $db->table($accountsTable)->where('id', $accountId->value)->update([
                    'status' => $target->value,
                    'version' => $account->version + 1,
                    'updated_at' => $now,
                ]);
            }

            $updated = $db->table($accountsTable)->where('id', $accountId->value)->first()
                ?? throw new RuntimeException("Account '{$accountId->value}' disappeared after status transition.");

            return self::hydrateAccount($updated);
        });
    }

    public function refundCredit(RefundToStoredValue $command): StoredValueCredit
    {
        if ($command->accountId !== null) {
            return $this->creditAccount(
                accountId: AccountId::fromString($command->accountId),
                amount: $command->amount,
                entryType: LedgerEntryType::RefundCredit,
                reference: $command->reference,
                idempotencyKey: $command->idempotencyKey,
                actorRef: $command->actorRef,
                reason: $command->reason,
            );
        }

        if ($command->customer === null) {
            throw new InvalidCommand('customer', 'refundCredit requires either accountId or customer', 'stored_value.invalid_account');
        }

        $wallet = $this->getOrCreateWallet($command->customer, $command->amount->currency);

        return $this->creditAccount(
            accountId: $wallet->id,
            amount: $command->amount,
            entryType: LedgerEntryType::RefundCredit,
            reference: $command->reference,
            idempotencyKey: $command->idempotencyKey,
            actorRef: $command->actorRef,
            reason: $command->reason,
        );
    }

    private function postJournal(
        string $accountId,
        ?string $holdId,
        LedgerEntryType $entryType,
        LedgerDirection $accountDirection,
        string $counterpartAccount,
        string $currency,
        int $amountMinor,
        int $balanceDeltaMinor,
        int $heldDeltaMinor,
        int $balanceAfterMinor,
        int $heldAfterMinor,
        ?string $reference,
        ?string $idempotencyKey,
        ?string $actorRef,
        ?string $reason,
        string $occurredAt,
    ): string {
        $journalId = Uuid7::generate();
        $counterpartDirection = $accountDirection === LedgerDirection::Debit
            ? LedgerDirection::Credit
            : LedgerDirection::Debit;

        $this->db()->table($this->tables->table('stored_value_ledger'))->insert([
            [
                'journal_id' => $journalId,
                'account_id' => $accountId,
                'hold_id' => $holdId,
                'entry_type' => $entryType->value,
                'entry_side' => LedgerEntrySide::Account->value,
                'direction' => $accountDirection->value,
                'ledger_account' => "liability:stored_value:{$accountId}",
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'balance_delta_minor' => $balanceDeltaMinor,
                'held_delta_minor' => $heldDeltaMinor,
                'balance_after_minor' => $balanceAfterMinor,
                'held_after_minor' => $heldAfterMinor,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'actor_ref' => $actorRef,
                'reason' => $reason,
                'occurred_at' => $occurredAt,
            ],
            [
                'journal_id' => $journalId,
                'account_id' => $accountId,
                'hold_id' => $holdId,
                'entry_type' => $entryType->value,
                'entry_side' => LedgerEntrySide::Counterpart->value,
                'direction' => $counterpartDirection->value,
                'ledger_account' => $counterpartAccount,
                'currency' => $currency,
                'amount_minor' => $amountMinor,
                'balance_delta_minor' => 0,
                'held_delta_minor' => 0,
                'balance_after_minor' => $balanceAfterMinor,
                'held_after_minor' => $heldAfterMinor,
                'reference' => $reference,
                'idempotency_key' => $idempotencyKey,
                'actor_ref' => $actorRef,
                'reason' => $reason,
                'occurred_at' => $occurredAt,
            ],
        ]);

        return $journalId;
    }

    private static function hydrateAccount(object $row): AccountSnapshot
    {
        $currency = Currency::of(Rows::string($row, 'currency'));
        $customerRaw = Rows::nullableString($row, 'customer_ref');
        $expiresRaw = Rows::nullableString($row, 'expires_at');
        $metadataRaw = Rows::json($row, 'metadata');
        $metadata = [];

        foreach ($metadataRaw as $key => $value) {
            if (is_string($key)) {
                $metadata[$key] = $value;
            }
        }

        return new AccountSnapshot(
            id: AccountId::fromString(Rows::string($row, 'id')),
            kind: StoredValueKind::from(Rows::string($row, 'kind')),
            status: AccountStatus::from(Rows::string($row, 'status')),
            currency: $currency,
            codeLast4: Rows::nullableString($row, 'code_last4'),
            customer: $customerRaw !== null ? CustomerRef::fromString($customerRaw) : null,
            initialBalance: Money::ofMinor(Rows::int($row, 'initial_minor'), $currency),
            balance: Money::ofMinor(Rows::int($row, 'balance_minor'), $currency),
            held: Money::ofMinor(Rows::int($row, 'held_minor'), $currency),
            version: Rows::int($row, 'version'),
            expiresAt: $expiresRaw !== null ? new DateTimeImmutable($expiresRaw) : null,
            metadata: $metadata,
            createdAt: Rows::date($row, 'created_at'),
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    private static function hydrateHold(object $row): StoredValueHold
    {
        $currency = Currency::of(Rows::string($row, 'currency'));
        $expiresRaw = Rows::nullableString($row, 'expires_at');

        return new StoredValueHold(
            holdId: Rows::string($row, 'id'),
            accountId: Rows::string($row, 'account_id'),
            orderRef: Rows::string($row, 'order_ref'),
            amount: Money::ofMinor(Rows::int($row, 'amount_minor'), $currency),
            captured: Money::ofMinor(Rows::int($row, 'captured_minor'), $currency),
            released: Money::ofMinor(Rows::int($row, 'released_minor'), $currency),
            state: Rows::string($row, 'state'),
            expiresAt: $expiresRaw !== null ? new DateTimeImmutable($expiresRaw) : null,
        );
    }

    private static function hydrateEntry(object $row): LedgerEntry
    {
        $currency = Currency::of(Rows::string($row, 'currency'));

        return new LedgerEntry(
            id: Rows::int($row, 'id'),
            journalId: Rows::string($row, 'journal_id'),
            accountId: AccountId::fromString(Rows::string($row, 'account_id')),
            holdId: Rows::nullableString($row, 'hold_id'),
            entryType: LedgerEntryType::from(Rows::string($row, 'entry_type')),
            entrySide: LedgerEntrySide::from(Rows::string($row, 'entry_side')),
            direction: LedgerDirection::from(Rows::string($row, 'direction')),
            ledgerAccount: Rows::string($row, 'ledger_account'),
            amount: Money::ofMinor(Rows::int($row, 'amount_minor'), $currency),
            balanceDeltaMinor: Rows::int($row, 'balance_delta_minor'),
            heldDeltaMinor: Rows::int($row, 'held_delta_minor'),
            balanceAfter: Money::ofMinor(Rows::int($row, 'balance_after_minor'), $currency),
            heldAfter: Money::ofMinor(Rows::int($row, 'held_after_minor'), $currency),
            reference: Rows::nullableString($row, 'reference'),
            idempotencyKey: Rows::nullableString($row, 'idempotency_key'),
            actorRef: Rows::nullableString($row, 'actor_ref'),
            reason: Rows::nullableString($row, 'reason'),
            occurredAt: Rows::date($row, 'occurred_at'),
        );
    }

    private function wrap(string $table): string
    {
        return $this->db()->table($table)->getGrammar()->wrapTable($table);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->commerz->connection);
    }
}
