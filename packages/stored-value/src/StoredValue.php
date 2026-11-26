<?php

declare(strict_types=1);

namespace Commerz\StoredValue;

use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\StoredValue\GiftCards\Contracts\GiftCards;
use Commerz\StoredValue\Ledger\Contracts\StoredValueLedger;
use Commerz\StoredValue\Wallets\Contracts\CustomerWallets;
use Packman\Attributes\Api;

/** Root of the stored-value API: accessors for gift cards, customer wallets, double-entry ledger, and tender port. */
#[Api(since: '0.1')]
final readonly class StoredValue
{
    public function __construct(
        private GiftCards $giftCards,
        private CustomerWallets $wallets,
        private StoredValueLedger $ledger,
        private StoredValueTender $tender,
    ) {}

    public function giftCards(): GiftCards
    {
        return $this->giftCards;
    }

    public function wallets(): CustomerWallets
    {
        return $this->wallets;
    }

    public function ledger(): StoredValueLedger
    {
        return $this->ledger;
    }

    public function tender(): StoredValueTender
    {
        return $this->tender;
    }
}
