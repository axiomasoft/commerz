<?php

declare(strict_types=1);

namespace Commerz\Stores\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class StoreNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Store', $id, 'stores.store_not_found');
    }
}
