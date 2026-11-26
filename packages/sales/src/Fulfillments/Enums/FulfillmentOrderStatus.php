<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Enums;

use Commerz\Sales\Fulfillments\Data\FulfillmentOrder;
use Packman\Attributes\Api;

/**
 * Lifecycle stage of a warehouse assignment ({@see FulfillmentOrder})
 * between order placement and physical shipment ({@see FulfillmentStatus}).
 */
#[Api(since: '0.1')]
enum FulfillmentOrderStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Fulfilled || $this === self::Cancelled;
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Open => in_array($next, [self::InProgress, self::OnHold, self::Fulfilled, self::Cancelled], true),
            self::InProgress => in_array($next, [self::OnHold, self::Fulfilled, self::Cancelled], true),
            self::OnHold => in_array($next, [self::Open, self::InProgress, self::Cancelled], true),
            self::Fulfilled, self::Cancelled => false,
        };
    }

    public function allowsReassignment(): bool
    {
        return $this === self::Open || $this === self::OnHold;
    }
}
