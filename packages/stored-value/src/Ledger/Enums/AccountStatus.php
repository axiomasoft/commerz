<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Ledger\Enums;

use Packman\Attributes\Api;

/** Lifecycle state of a stored-value account. */
#[Api(since: '0.1')]
enum AccountStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
    case Expired = 'expired';
    case Voided = 'voided';
}
