<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The cart has lines that ship and no priced delivery method (`commerz-sales.checkout.require_delivery`). */
#[Api(since: '0.1')]
final class DeliveryRequired extends CommerzException
{
    public function __construct(public readonly string $cart)
    {
        parent::__construct("Cart '{$cart}' has lines that ship and no delivery method.");
    }

    public function code(): string
    {
        return 'sales.delivery_required';
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
