<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class CartNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Cart', $id, 'sales.cart_not_found');
    }
}
