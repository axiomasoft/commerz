<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Enums;

use Commerz\Attributes\Api;

/** `draft → in_transit → completed`, or `draft → cancelled`; completed and cancelled are final. */
#[Api(since: '0.1')]
enum TransferState: string
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
