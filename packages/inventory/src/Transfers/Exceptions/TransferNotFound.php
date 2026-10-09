<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class TransferNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Transfer', $id, 'inventory.transfer_not_found');
    }
}
