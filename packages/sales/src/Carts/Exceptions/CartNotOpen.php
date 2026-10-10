<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The cart was converted into an order and is no longer edited. */
#[Api(since: '0.1')]
final class CartNotOpen extends CommerzException
{
    public function __construct(public readonly string $cart, public readonly string $state)
    {
        parent::__construct("Cart '{$cart}' is {$state}, not open.");
    }

    public function code(): string
    {
        return 'sales.cart_not_open';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['cart' => $this->cart, 'state' => $this->state];
    }
}
