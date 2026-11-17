<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** A cancelled or completed order takes no new payment. */
#[Api(since: '0.1')]
final class OrderNotPayable extends CommerzException
{
    /** @param  string  $state  `cancelled` or `completed` */
    public function __construct(public readonly string $order, public readonly string $state)
    {
        parent::__construct("Order '{$order}' cannot be paid: it is {$state}.");
    }

    public function code(): string
    {
        return 'sales.order_not_payable';
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
            code: SalesErrorCode::OrderNotPayable,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
