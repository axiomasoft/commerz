<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Ledger\Data;

use Commerz\Money\Money;
use Commerz\StoredValue\Ledger\Enums\LedgerDirection;
use Commerz\StoredValue\Ledger\Enums\LedgerEntrySide;
use Commerz\StoredValue\Ledger\Enums\LedgerEntryType;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** One immutable row of `cmz_stored_value_ledger`. */
#[Api(since: '0.1')]
final readonly class LedgerEntry
{
    public function __construct(
        public int $id,
        public string $journalId,
        public AccountId $accountId,
        public ?string $holdId,
        public LedgerEntryType $entryType,
        public LedgerEntrySide $entrySide,
        public LedgerDirection $direction,
        public string $ledgerAccount,
        public Money $amount,
        public int $balanceDeltaMinor,
        public int $heldDeltaMinor,
        public Money $balanceAfter,
        public Money $heldAfter,
        public ?string $reference,
        public ?string $idempotencyKey,
        public ?string $actorRef,
        public ?string $reason,
        public DateTimeImmutable $occurredAt,
    ) {}
}
