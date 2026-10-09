<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Data;

use Commerz\Attributes\Api;

/** Ships the whole draft: the reserved units leave the source (movement `transfer_out`). */
#[Api(since: '0.1')]
final readonly class DispatchTransfer
{
    public function __construct(public TransferId $transfer) {}
}
