<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum RoundingStrategy: string
{
    case None = 'none';
    case Ending99 = 'ending_99';
    case Ending90 = 'ending_90';
    case MultipleOf5 = 'multiple_of_5';
    case MultipleOf10 = 'multiple_of_10';
    case MinorMultiple = 'minor_multiple';
}
