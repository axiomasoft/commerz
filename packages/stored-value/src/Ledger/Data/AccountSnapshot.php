<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Ledger\Data;

use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\StoredValue\Ledger\Enums\AccountStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Immutable read projection of a stored-value account's current state and balances. */
#[Api(since: '0.1')]
final readonly class AccountSnapshot
{
    public Money $available;

    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public AccountId $id,
        public StoredValueKind $kind,
        public AccountStatus $status,
        public Currency $currency,
        public ?string $codeLast4,
        public ?CustomerRef $customer,
        public Money $initialBalance,
        public Money $balance,
        public Money $held,
        public int $version,
        public ?DateTimeImmutable $expiresAt,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        $this->available = $balance->minus($held);
    }

    public function isExpiredAt(DateTimeImmutable $instant): bool
    {
        return $this->status === AccountStatus::Expired
            || ($this->expiresAt !== null && $this->expiresAt <= $instant);
    }
}
