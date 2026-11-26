<?php

declare(strict_types=1);

namespace Commerz\B2b\PaymentTerms\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum CreditHoldState: string
{
    case Reserved = 'reserved';
    case Captured = 'captured';
    case Released = 'released';
}
