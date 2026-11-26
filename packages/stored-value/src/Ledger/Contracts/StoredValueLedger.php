<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Ledger\Contracts;

use Commerz\StoredValue\Ledger\Data\AccountId;
use Commerz\StoredValue\Ledger\Data\LedgerEntry;
use Commerz\StoredValue\Ledger\Enums\LedgerEntrySide;
use Packman\Attributes\Api;

/** Read-only query and trial-balance verification surface for the immutable stored-value double-entry ledger. */
#[Api(since: '0.1')]
interface StoredValueLedger
{
    /**
     * Lists ledger entries for an account in ascending posting order (`id ASC`).
     *
     * @return list<LedgerEntry>
     */
    public function entriesForAccount(AccountId $accountId, ?LedgerEntrySide $side = null, int $limit = 100): array;

    /**
     * Returns both balanced legs of a double-entry journal by `journalId`.
     *
     * @return list<LedgerEntry>
     */
    public function journalEntries(string $journalId): array;

    /**
     * Verifies the double-entry trial balance for an account (`sum(debits) === sum(credits)` and `INV-SV-001`).
     */
    public function isBalanced(AccountId $accountId): bool;
}
