<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Lifecycle\Enums;

use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContract;
use Packman\Attributes\Api;

/**
 * Lifecycle status of a {@see SubscriptionContract}.
 */
#[Api(since: '0.1')]
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case PastDue = 'past_due';
    case Canceled = 'canceled';

    public function canTransitionTo(self $target): bool
    {
        if ($this === self::Canceled) {
            return false;
        }

        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::Active => in_array($target, [self::Paused, self::PastDue, self::Canceled], true),
            self::Paused => in_array($target, [self::Active, self::Canceled], true),
            self::PastDue => in_array($target, [self::Active, self::Paused, self::Canceled], true),
        };
    }
}
