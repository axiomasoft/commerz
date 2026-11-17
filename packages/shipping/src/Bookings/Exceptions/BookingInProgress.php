<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** Another process is booking the same reference right now; repeat later to get its outcome. */
#[Api(since: '0.1')]
final class BookingInProgress extends CommerzException
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct("Shipment '{$reference}' is being booked by another process.");
    }

    public function code(): string
    {
        return 'shipping.booking_in_progress';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['reference' => $this->reference];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ShippingErrorCode::BookingInProgress,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
