<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum SellingPlanBillingKind: string
{
    case OneTime = 'one_time';
    case Recurring = 'recurring';
    case Deposit = 'deposit';
    case Deferred = 'deferred';

    public function isRecurring(): bool
    {
        return $this === self::Recurring;
    }
}
