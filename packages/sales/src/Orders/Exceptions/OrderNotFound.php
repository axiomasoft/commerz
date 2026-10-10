<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class OrderNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Order', $id, 'sales.order_not_found');
    }
}
