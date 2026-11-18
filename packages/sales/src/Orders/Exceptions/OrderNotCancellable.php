<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The order cannot be cancelled: it is completed or cancelled, or (for a whole cancellation) partly fulfilled. */
#[Api(since: '0.1')]
final class OrderNotCancellable extends CommerzException
{
    /** @param  string  $reason  `completed`, `cancelled` or `fulfilled` */
    public function __construct(public readonly string $order, public readonly string $reason)
    {
        parent::__construct("Order '{$order}' cannot be cancelled: {$reason}.");
    }

    public function code(): string
    {
        return 'sales.order_not_cancellable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['order' => $this->order, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::OrderNotCancellable,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
