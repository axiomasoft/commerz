<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Ledger\Enums;

use Packman\Attributes\Api;

/** Double-entry ledger event types for stored-value accounts. */
#[Api(since: '0.1')]
enum LedgerEntryType: string
{
    case Issue = 'issue';
    case Hold = 'hold';
    case Release = 'release';
    case Capture = 'capture';
    case RefundCredit = 'refund_credit';
    case Expire = 'expire';
}
