<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The cart changed between its quote and the placement; quote it again. */
#[Api(since: '0.1')]
final class CartChanged extends CommerzException
{
    public function __construct(public readonly string $cart, public readonly int $expectedVersion, public readonly int $actualVersion)
    {
        parent::__construct("Cart '{$cart}' changed while the order was being placed.");
    }

    public function code(): string
    {
        return 'sales.cart_changed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['cart' => $this->cart, 'expected_version' => $this->expectedVersion, 'actual_version' => $this->actualVersion];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::CartChanged,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
