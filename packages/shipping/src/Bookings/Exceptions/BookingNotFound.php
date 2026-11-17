<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

#[Api(since: '0.1')]
final class BookingNotFound extends NotFound
{
    public function __construct(string $reference)
    {
        parent::__construct('Shipment booking', $reference, 'shipping.booking_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ShippingErrorCode::BookingNotFound,
            category: PackmanErrorCategory::NotFound,
            retryable: false,
            details: $this->context(),
        );
    }
}
