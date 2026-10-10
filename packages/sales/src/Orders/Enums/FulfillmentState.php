<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Enums;

use Commerz\Attributes\Api;

/** Derived from the ledger of the order lines, never set by a command. */
#[Api(since: '0.1')]
enum FulfillmentState: string
{
    case Unfulfilled = 'unfulfilled';
    case PartiallyFulfilled = 'partially_fulfilled';
    case Fulfilled = 'fulfilled';
    case PartiallyReturned = 'partially_returned';
    case Returned = 'returned';
}
