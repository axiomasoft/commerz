<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Wallets\Contracts;

use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Ports\StoredValue\Data\StoredValueCredit;
use Commerz\StoredValue\Ledger\Data\AccountId;
use Commerz\StoredValue\Wallets\Data\CreditWallet;
use Commerz\StoredValue\Wallets\Data\CustomerWalletView;
use Packman\Attributes\Api;

/** Manages per-customer, per-currency store-credit wallets. */
#[Api(since: '0.1')]
interface CustomerWallets
{
    public function getOrCreate(CustomerRef $customer, Currency $currency): CustomerWalletView;

    public function find(AccountId $id): ?CustomerWalletView;

    public function findByCustomer(CustomerRef $customer, Currency $currency): ?CustomerWalletView;

    public function credit(CreditWallet $command): StoredValueCredit;

    public function freeze(AccountId $id, ?string $reason = null): CustomerWalletView;

    public function unfreeze(AccountId $id): CustomerWalletView;
}
