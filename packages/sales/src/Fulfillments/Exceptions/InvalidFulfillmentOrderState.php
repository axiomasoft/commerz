<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Commerz\Sales\Fulfillments\Data\FulfillmentOrder;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** Thrown when a {@see FulfillmentOrder} cannot perform the requested transition. */
#[Api(since: '0.1')]
final class InvalidFulfillmentOrderState extends CommerzException
{
    public function __construct(
        public readonly string $fulfillmentOrder,
        public readonly string $state,
        public readonly string $action,
    ) {
        parent::__construct("FulfillmentOrder '{$fulfillmentOrder}' in state '{$state}' cannot perform '{$action}'.");
    }

    public function code(): string
    {
        return 'sales.invalid_fulfillment_order_state';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return [
            'fulfillment_order' => $this->fulfillmentOrder,
            'state' => $this->state,
            'action' => $this->action,
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::InvalidFulfillmentOrderState,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
