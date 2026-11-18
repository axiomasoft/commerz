<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** A shipment was booked inside a database transaction, which could roll back after the carrier created it (SHP-04). */
#[Api(since: '0.1')]
final class BookingInsideTransaction extends CommerzException
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct("Shipment '{$reference}' must be booked outside a database transaction.");
    }

    public function code(): string
    {
        return 'shipping.booking_inside_transaction';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['reference' => $this->reference];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: ShippingErrorCode::BookingInsideTransaction,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->context(),
        );
    }
}
