<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::CartNotOpen,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
