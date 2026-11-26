<?php

declare(strict_types=1);

namespace Commerz\StoredValue\GiftCards\Data;

use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\StoredValue\Ledger\Data\AccountId;
use Commerz\StoredValue\Ledger\Data\AccountSnapshot;
use Commerz\StoredValue\Ledger\Enums\AccountStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Read projection of a gift card (never exposes plaintext code or code hash). */
#[Api(since: '0.1')]
final readonly class GiftCardView
{
    public Money $available;

    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public AccountId $id,
        public AccountStatus $status,
        public Currency $currency,
        public string $codeLast4,
        public ?CustomerRef $recipient,
        public Money $initialBalance,
        public Money $balance,
        public Money $held,
        public ?DateTimeImmutable $expiresAt,
        public array $metadata,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        $this->available = $balance->minus($held);
    }

    public static function fromSnapshot(AccountSnapshot $snapshot): self
    {
        return new self(
            id: $snapshot->id,
            status: $snapshot->status,
            currency: $snapshot->currency,
            codeLast4: $snapshot->codeLast4 ?? '',
            recipient: $snapshot->customer,
            initialBalance: $snapshot->initialBalance,
            balance: $snapshot->balance,
            held: $snapshot->held,
            expiresAt: $snapshot->expiresAt,
            metadata: $snapshot->metadata,
            createdAt: $snapshot->createdAt,
            updatedAt: $snapshot->updatedAt,
        );
    }
}
