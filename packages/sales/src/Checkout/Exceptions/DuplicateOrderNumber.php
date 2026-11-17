<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** The order number generator returned a number the store already has; nothing was placed. */
#[Api(since: '0.1')]
final class DuplicateOrderNumber extends CommerzException
{
    public function __construct(public readonly string $store, public readonly string $number)
    {
        parent::__construct("Order number '{$number}' is already taken in store '{$store}'.");
    }

    public function code(): string
    {
        return 'sales.duplicate_order_number';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['store' => $this->store, 'number' => $this->number];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::DuplicateOrderNumber,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
