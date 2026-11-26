<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Enums;

use Packman\Attributes\Api;

/** Distinguishes prepaid gift cards from customer store-credit wallets. */
#[Api(since: '0.1')]
enum StoredValueKind: string
{
    case GiftCard = 'gift_card';
    case Wallet = 'wallet';
}
