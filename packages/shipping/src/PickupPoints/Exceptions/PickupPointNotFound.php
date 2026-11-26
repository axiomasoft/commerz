<?php

declare(strict_types=1);

namespace Commerz\Shipping\PickupPoints\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class PickupPointNotFound extends NotFound
{
    public function __construct(string $identifier)
    {
        parent::__construct('Pickup point', $identifier, 'shipping.pickup_point_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ShippingErrorCode::PickupPointNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
