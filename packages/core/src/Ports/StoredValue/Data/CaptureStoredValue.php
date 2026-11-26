<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/** Captures all or part of an active stored-value hold. */
#[Api(since: '0.1')]
final readonly class CaptureStoredValue
{
    public string $holdId;

    public string $reference;

    public string $idempotencyKey;

    public function __construct(
        string $holdId,
        string $reference,
        string $idempotencyKey,
        public ?Money $amount = null,
        public ?string $actorRef = null,
        public ?string $reason = null,
    ) {
        $holdId = trim($holdId);
        $reference = trim($reference);
        $idempotencyKey = trim($idempotencyKey);

        if ($holdId === '') {
            throw new InvalidCommand('holdId', 'must be non-empty', 'stored_value.invalid_hold');
        }

        if ($reference === '' || strlen($reference) > 191) {
            throw new InvalidCommand('reference', 'must be 1..191 bytes', 'stored_value.invalid_reference');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new InvalidCommand('idempotencyKey', 'must be 1..191 bytes', 'stored_value.invalid_idempotency_key');
        }

        if ($amount !== null && ! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'capture amount must be positive when provided', 'stored_value.invalid_amount');
        }

        $this->holdId = $holdId;
        $this->reference = $reference;
        $this->idempotencyKey = $idempotencyKey;
    }
}
