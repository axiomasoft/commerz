<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Enums;

use Packman\Attributes\Api;

/** The stage of a return: `requested` (open), `received` (the goods came back) or `cancelled`; the last two are final. */
#[Api(since: '0.1')]
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
