<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The cart was already placed; `$order` is the order it became (ORD-01). */
#[Api(since: '0.1')]
final class CartAlreadyPlaced extends CommerzException
{
    public function __construct(public readonly string $cart, public readonly ?string $order)
    {
        parent::__construct("Cart '{$cart}' was already placed as an order.");
    }

    public function code(): string
    {
        return 'sales.cart_already_placed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['cart' => $this->cart, 'order' => $this->order];
    }
}
