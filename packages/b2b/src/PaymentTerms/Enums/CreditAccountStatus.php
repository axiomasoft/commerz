<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum CreditAccountStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';
}
