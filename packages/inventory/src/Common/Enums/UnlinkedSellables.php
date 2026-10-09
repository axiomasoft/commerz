<?php

declare(strict_types=1);

namespace Commerz\Inventory\Common\Enums;

use Commerz\Attributes\Api;

/** How inventory treats a sellable that has no stock item link (`commerz-inventory.unlinked_sellables`). */
#[Api(since: '0.1')]
enum UnlinkedSellables: string
{
    /** Nothing to sell: availability is 0 and a reservation fails. */
    case Unavailable = 'unavailable';

    /** Always available; nothing is held. */
    case Unlimited = 'unlimited';
}
