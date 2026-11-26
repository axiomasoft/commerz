<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Enums;

use Commerz\Sales\Payments\Contracts\PaymentCustomization;
use Packman\Attributes\Api;

/** Mutation a {@see PaymentCustomization} can apply to a checkout payment method. */
#[Api(since: '0.1')]
enum PaymentCustomizationAction: string
{
    case Hide = 'hide';
    case Rename = 'rename';
    case Move = 'move';
}
