<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Events;

use Commerz\Attributes\Api;
use Commerz\Inventory\Transfers\Data\TransferView;

/** A transfer was created, dispatched, received (in part or in full) or cancelled in a committed operation. */
#[Api(since: '0.1')]
final readonly class TransferUpdated
{
    public function __construct(public TransferView $transfer) {}
}
