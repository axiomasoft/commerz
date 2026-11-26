<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/** Releases all or part of an active stored-value hold back to available balance. */
#[Api(since: '0.1')]
final readonly class ReleaseStoredValue
{
    public string $holdId;

    public string $idempotencyKey;

    public function __construct(
        string $holdId,
        string $idempotencyKey,
        public ?Money $amount = null,
        public ?string $actorRef = null,
        public ?string $reason = null,
    ) {
        $holdId = trim($holdId);
        $idempotencyKey = trim($idempotencyKey);

        if ($holdId === '') {
            throw new InvalidCommand('holdId', 'must be non-empty', 'stored_value.invalid_hold');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new InvalidCommand('idempotencyKey', 'must be 1..191 bytes', 'stored_value.invalid_idempotency_key');
        }

        if ($amount !== null && ! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'release amount must be positive when provided', 'stored_value.invalid_amount');
        }

        $this->holdId = $holdId;
        $this->idempotencyKey = $idempotencyKey;
    }
}
