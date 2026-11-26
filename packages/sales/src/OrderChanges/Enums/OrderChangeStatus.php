<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Enums;

use Packman\Attributes\Api;

/** Lifecycle state of a two-phase order change. */
#[Api(since: '0.1')]
enum OrderChangeStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
