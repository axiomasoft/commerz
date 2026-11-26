<?php

declare(strict_types=1);

namespace Commerz\Ports\Taxes\Enums;

use Packman\Attributes\Api;

/** How a buyer or transaction is relieved from standard tax collection. */
#[Api(since: '0.1')]
enum TaxExemptionType: string
{
    case Exempt = 'exempt';
    case ReverseCharge = 'reverse_charge';
}
