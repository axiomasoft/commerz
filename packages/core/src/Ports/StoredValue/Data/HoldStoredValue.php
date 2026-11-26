<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

/** Places an idempotent authorization hold on a stored-value account for an order or checkout. */
#[Api(since: '0.1')]
final readonly class HoldStoredValue
{
    public string $accountId;

    public string $orderRef;

    public string $idempotencyKey;

    public ?DateTimeImmutable $expiresAt;

    public function __construct(
        string $accountId,
        string $orderRef,
        public Money $amount,
        string $idempotencyKey,
        ?DateTimeImmutable $expiresAt = null,
        public ?string $actorRef = null,
        public ?string $reason = null,
    ) {
        $accountId = trim($accountId);
        $orderRef = trim($orderRef);
        $idempotencyKey = trim($idempotencyKey);

        if ($accountId === '') {
            throw new InvalidCommand('accountId', 'must be non-empty', 'stored_value.invalid_account');
        }

        if ($orderRef === '' || strlen($orderRef) > 191) {
            throw new InvalidCommand('orderRef', 'must be 1..191 bytes', 'stored_value.invalid_reference');
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new InvalidCommand('idempotencyKey', 'must be 1..191 bytes', 'stored_value.invalid_idempotency_key');
        }

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'hold amount must be positive', 'stored_value.invalid_amount');
        }

        $this->accountId = $accountId;
        $this->orderRef = $orderRef;
        $this->idempotencyKey = $idempotencyKey;
        $this->expiresAt = $expiresAt?->setTimezone(new DateTimeZone('UTC'));
    }
}
