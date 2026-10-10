<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

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
}
