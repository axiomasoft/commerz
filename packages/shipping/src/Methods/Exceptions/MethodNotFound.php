<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class MethodNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Shipping method', $id, 'shipping.method_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ShippingErrorCode::MethodNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
