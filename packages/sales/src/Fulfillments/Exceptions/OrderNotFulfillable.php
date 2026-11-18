<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A cancelled or completed order takes no new fulfillment. */
#[Api(since: '0.1')]
final class OrderNotFulfillable extends CommerzException
{
    /** @param  string  $state  `cancelled` or `completed` */
    public function __construct(public readonly string $order, public readonly string $state)
    {
        parent::__construct("Order '{$order}' cannot be fulfilled: it is {$state}.");
    }

    public function code(): string
    {
        return 'sales.order_not_fulfillable';
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
            code: SalesErrorCode::OrderNotFulfillable,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
