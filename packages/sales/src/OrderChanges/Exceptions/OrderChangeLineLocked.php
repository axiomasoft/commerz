<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** An order line cannot be reduced below its fulfilled/cancelled ledger count or removed once fulfilled. */
#[Api(since: '0.1')]
final class OrderChangeLineLocked extends CommerzException
{
    public function __construct(
        public readonly string $lineId,
        public readonly int $requestedQuantity,
        public readonly int $lockedQuantity,
    ) {
        parent::__construct("Order line '{$lineId}' cannot be changed to quantity {$requestedQuantity}: {$lockedQuantity} unit(s) are already fulfilled or cancelled.");
    }

    public function code(): string
    {
        return SalesErrorCode::OrderChangeLineLocked->value;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'line' => $this->lineId,
            'requested_quantity' => $this->requestedQuantity,
            'locked_quantity' => $this->lockedQuantity,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::OrderChangeLineLocked,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
