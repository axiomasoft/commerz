<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Enums;

use Packman\Attributes\Api;

/**
 * The status of one payment attempt (D33). `initiating` while the gateway is being asked, `unknown` when its answer
 * was lost (reconcile it); `failed` and `cancelled` are final, though money that arrives later is still `captured`.
 */
#[Api(since: '0.1')]
enum PaymentStatus: string
{
    case Initiating = 'initiating';
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';

    /** Captured, failed or cancelled: the gateway has nothing more to tell. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Captured, self::Failed, self::Cancelled], true);
    }
}
