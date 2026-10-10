<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Enums;

use Commerz\Attributes\Api;

/** What a provider reports about a payment in an event (a webhook, or the operator recording offline money). */
#[Api(since: '0.1')]
enum PaymentEventType: string
{
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function status(): PaymentStatus
    {
        return match ($this) {
            self::Authorized => PaymentStatus::Authorized,
            self::Captured => PaymentStatus::Captured,
            self::Failed => PaymentStatus::Failed,
            self::Cancelled => PaymentStatus::Cancelled,
        };
    }
}
