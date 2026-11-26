<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Wallets\Data;

use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\StoredValue\Ledger\Data\AccountId;
use Commerz\StoredValue\Ledger\Data\AccountSnapshot;
use Commerz\StoredValue\Ledger\Enums\AccountStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;
use RuntimeException;

/** Read projection of a customer store-credit wallet for one currency. */
#[Api(since: '0.1')]
final readonly class CustomerWalletView
{
    public Money $available;

    public function __construct(
        public AccountId $id,
        public CustomerRef $customer,
        public AccountStatus $status,
        public Currency $currency,
        public Money $balance,
        public Money $held,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        $this->available = $balance->minus($held);
    }

    public static function fromSnapshot(AccountSnapshot $snapshot): self
    {
        $customer = $snapshot->customer
            ?? throw new RuntimeException("Wallet account '{$snapshot->id->value}' is missing customer_ref.");

        return new self(
            id: $snapshot->id,
            customer: $customer,
            status: $snapshot->status,
            currency: $snapshot->currency,
            balance: $snapshot->balance,
            held: $snapshot->held,
            createdAt: $snapshot->createdAt,
            updatedAt: $snapshot->updatedAt,
        );
    }
}
