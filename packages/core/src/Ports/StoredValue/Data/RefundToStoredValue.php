<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/** Credits a stored-value account (or customer store-credit wallet) for an order refund. */
#[Api(since: '0.1')]
final readonly class RefundToStoredValue
{
    public ?string $accountId;

    public string $reference;

    public string $idempotencyKey;

    public function __construct(
        public Money $amount,
        string $reference,
        string $idempotencyKey,
        ?string $accountId = null,
        public ?CustomerRef $customer = null,
        public ?string $actorRef = null,
        public ?string $reason = null,
    ) {
        $accountId = $accountId !== null ? trim($accountId) : null;
        $reference = trim($reference);
        $idempotencyKey = trim($idempotencyKey);

        if ($accountId === '') {
            throw new InvalidCommand('accountId', 'must be non-empty when provided', 'stored_value.invalid_account');
        }

        if ($accountId === null && $customer === null) {
            throw new InvalidCommand('accountId', 'refund credit needs either accountId or customer', 'stored_value.invalid_account');
        }

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'refund credit amount must be positive', 'stored_value.invalid_amount');
        }

        if ($reference === '' || strlen($reference) > 191) {
            throw new InvalidCommand('reference', 'must be 1..191 bytes', 'stored_value.invalid_reference');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new InvalidCommand('idempotencyKey', 'must be 1..191 bytes', 'stored_value.invalid_idempotency_key');
        }

        $this->accountId = $accountId;
        $this->reference = $reference;
        $this->idempotencyKey = $idempotencyKey;
    }
}
