<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Enums;

use Packman\Attributes\Api;

/** Whether a recorded provider event changed the payment; an event that would move it backwards is ignored. */
#[Api(since: '0.1')]
enum PaymentEventOutcome: string
{
    case Applied = 'applied';
    case Ignored = 'ignored';
}
