<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Billing\Enums;

use Commerz\Subscriptions\Billing\Data\BillingCycle;
use Packman\Attributes\Api;

/**
 * State of a single subscription {@see BillingCycle}.
 */
#[Api(since: '0.1')]
enum BillingCycleStatus: string
{
    case Scheduled = 'scheduled';
    case Retrying = 'retrying';
    case Billed = 'billed';
    case Skipped = 'skipped';
    case Canceled = 'canceled';
    case Failed = 'failed';

    public function isRunnable(): bool
    {
        return $this === self::Scheduled || $this === self::Retrying;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Billed, self::Skipped, self::Canceled, self::Failed], true);
    }
}
