<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Wallets\Internal;

use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Ports\StoredValue\Data\StoredValueCredit;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\StoredValue\Ledger\Data\AccountId;
use Commerz\StoredValue\Ledger\Enums\AccountStatus;
use Commerz\StoredValue\Ledger\Internal\DoubleEntryLedger;
use Commerz\StoredValue\Wallets\Contracts\CustomerWallets;
use Commerz\StoredValue\Wallets\Data\CreditWallet;
use Commerz\StoredValue\Wallets\Data\CustomerWalletView;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseCustomerWallets implements CustomerWallets
{
    public function __construct(private DoubleEntryLedger $ledger) {}

    #[Override]
    public function getOrCreate(CustomerRef $customer, Currency $currency): CustomerWalletView
    {
        return CustomerWalletView::fromSnapshot($this->ledger->getOrCreateWallet($customer, $currency));
    }

    #[Override]
    public function find(AccountId $id): ?CustomerWalletView
    {
        $snapshot = $this->ledger->findAccount($id);

        if ($snapshot === null || $snapshot->kind !== StoredValueKind::Wallet) {
            return null;
        }

        return CustomerWalletView::fromSnapshot($snapshot);
    }

    #[Override]
    public function findByCustomer(CustomerRef $customer, Currency $currency): ?CustomerWalletView
    {
        $snapshot = $this->ledger->findWalletByCustomer($customer, $currency);

        return $snapshot === null ? null : CustomerWalletView::fromSnapshot($snapshot);
    }

    #[Override]
    public function credit(CreditWallet $command): StoredValueCredit
    {
        $wallet = $this->ledger->getOrCreateWallet($command->customer, $command->amount->currency);

        return $this->ledger->creditAccount(
            accountId: $wallet->id,
            amount: $command->amount,
            entryType: $command->entryType,
            reference: $command->reference,
            idempotencyKey: $command->idempotencyKey,
            actorRef: $command->actorRef,
            reason: $command->reason,
        );
    }

    #[Override]
    public function freeze(AccountId $id, ?string $reason = null): CustomerWalletView
    {
        return CustomerWalletView::fromSnapshot($this->ledger->transitionStatus($id, AccountStatus::Frozen, $reason));
    }

    #[Override]
    public function unfreeze(AccountId $id): CustomerWalletView
    {
        return CustomerWalletView::fromSnapshot($this->ledger->transitionStatus($id, AccountStatus::Active));
    }
}
