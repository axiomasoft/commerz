<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Ledger\Enums;

use Packman\Attributes\Api;

/** Double-entry posting direction. Every journal balances debits == credits. */
#[Api(since: '0.1')]
enum LedgerDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
