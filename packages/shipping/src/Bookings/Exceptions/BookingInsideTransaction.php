<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

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
        return ErrorCategory::Configuration;
    }

    public function context(): array
    {
        return ['reference' => $this->reference];
    }
}
