<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum PaymentTermsType: string
{
    case Net15 = 'net_15';
    case Net30 = 'net_30';
    case Net60 = 'net_60';
    case DepositSchedule = 'deposit_schedule';
}
