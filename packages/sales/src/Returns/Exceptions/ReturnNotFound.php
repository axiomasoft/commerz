<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class ReturnNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Return', $id, 'sales.return_not_found');
    }
}
