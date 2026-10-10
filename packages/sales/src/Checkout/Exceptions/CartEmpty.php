<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** An order needs at least one line. */
#[Api(since: '0.1')]
final class CartEmpty extends CommerzException
{
    public function __construct(public readonly string $cart)
    {
        parent::__construct("Cart '{$cart}' has no lines.");
    }

    public function code(): string
    {
        return 'sales.cart_empty';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['cart' => $this->cart];
    }
}
