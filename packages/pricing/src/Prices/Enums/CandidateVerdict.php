<?php

declare(strict_types=1);

namespace Commerz\Pricing\Prices\Enums;

use Packman\Attributes\Api;

/** What became of one price among the candidates of a sellable (`Prices::explain`). */
#[Api(since: '0.1')]
enum CandidateVerdict: string
{
    /** The winner: the first applicable price in the ranking order. */
    case Selected = 'selected';

    /** Applicable, but ranked below the winner. */
    case Outranked = 'outranked';

    /** Meant for a customer group the context is not in. */
    case GroupMismatch = 'group_mismatch';

    /** Starts from a larger quantity than requested. */
    case BelowMinQuantity = 'below_min_quantity';

    /** Not valid at the moment of the context. */
    case OutsideWindow = 'outside_window';
}
