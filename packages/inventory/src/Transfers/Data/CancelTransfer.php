<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Data;

use Packman\Attributes\Api;

/** Cancels a draft and releases its reservation; shipped units cannot be called back. */
#[Api(since: '0.1')]
final readonly class CancelTransfer
{
    public function __construct(public TransferId $transfer) {}
}
