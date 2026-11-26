<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Wallets\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\StoredValue\Ledger\Enums\LedgerEntryType;
use Packman\Attributes\Api;

/** Command to credit a customer's store-credit wallet (either via `issue` or `refund_credit`). */
#[Api(since: '0.1')]
final readonly class CreditWallet
{
    public string $reference;

    public string $idempotencyKey;

    public function __construct(
        public CustomerRef $customer,
        public Money $amount,
        string $reference,
        string $idempotencyKey,
        public LedgerEntryType $entryType = LedgerEntryType::RefundCredit,
        public ?string $actorRef = null,
        public ?string $reason = null,
    ) {
        $reference = trim($reference);
        $idempotencyKey = trim($idempotencyKey);

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'wallet credit amount must be positive', 'stored_value.invalid_amount');
        }

        if ($reference === '' || strlen($reference) > 191) {
            throw new InvalidCommand('reference', 'must be 1..191 bytes', 'stored_value.invalid_reference');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new InvalidCommand('idempotencyKey', 'must be 1..191 bytes', 'stored_value.invalid_idempotency_key');
        }

        if (! in_array($entryType, [LedgerEntryType::Issue, LedgerEntryType::RefundCredit], true)) {
            throw new InvalidCommand('entryType', 'wallet credit entryType must be issue or refund_credit', 'stored_value.invalid_amount');
        }

        $this->reference = $reference;
        $this->idempotencyKey = $idempotencyKey;
    }
}
