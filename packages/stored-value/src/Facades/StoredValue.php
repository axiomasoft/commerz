<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Facades;

use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\StoredValue\GiftCards\Contracts\GiftCards;
use Commerz\StoredValue\Ledger\Contracts\StoredValueLedger;
use Commerz\StoredValue\Wallets\Contracts\CustomerWallets;
use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Api;

/**
 * @method static GiftCards giftCards()
 * @method static CustomerWallets wallets()
 * @method static StoredValueLedger ledger()
 * @method static StoredValueTender tender()
 *
 * @see \Commerz\StoredValue\StoredValue
 */
#[Api(since: '0.1')]
final class StoredValue extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Commerz\StoredValue\StoredValue::class;
    }
}
