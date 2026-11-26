<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Ledger\Enums;

use Packman\Attributes\Api;

/** Distinguishes the stored-value account leg from its balancing counterpart leg. */
#[Api(since: '0.1')]
enum LedgerEntrySide: string
{
    case Account = 'account';
    case Counterpart = 'counterpart';
}
