<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class FulfillmentOrderNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('FulfillmentOrder', $id, 'sales.fulfillment_order_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::FulfillmentOrderNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
