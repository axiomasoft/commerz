<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The order is not in a mutable state (e.g. already `completed` or `cancelled`). */
#[Api(since: '0.1')]
final class OrderNotEditable extends CommerzException
{
    public function __construct(public readonly string $order, public readonly string $state)
    {
        parent::__construct("Order '{$order}' cannot be edited while '{$state}'.");
    }

    public function code(): string
    {
        return SalesErrorCode::OrderNotEditable->value;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['order' => $this->order, 'state' => $this->state];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::OrderNotEditable,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
