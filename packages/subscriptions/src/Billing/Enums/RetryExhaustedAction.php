<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Billing\Enums;

use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContract;
use Packman\Attributes\Api;

/**
 * Action applied to a {@see SubscriptionContract} when all retry attempts of a
 * billing cycle are exhausted.
 */
#[Api(since: '0.1')]
enum RetryExhaustedAction: string
{
    case Cancel = 'cancel';
    case Pause = 'pause';
    case KeepPastDue = 'keep_past_due';
}
