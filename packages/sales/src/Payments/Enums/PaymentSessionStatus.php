<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Enums;

use Packman\Attributes\Api;

/** Lifecycle state of one payment session inside a payment collection. */
#[Api(since: '0.1')]
enum PaymentSessionStatus: string
{
    case Pending = 'pending';
    case Authorizing = 'authorizing';
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case PartiallyCaptured = 'partially_captured';
    case Captured = 'captured';
    case Voided = 'voided';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function isFinal(): bool
    {
        return in_array($this, [self::Captured, self::Voided, self::Failed], true);
    }

    /** Whether this session still holds active authorization or funds against the collection target. */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Voided, self::Failed], true);
    }
}
