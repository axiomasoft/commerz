<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Throwable;

/**
 * The carrier call failed in a way that does not tell whether the shipment was created (a timeout, a broken
 * connection); `getPrevious()` is the original error. Booking the same reference again asks the carrier first.
 */
#[Api(since: '0.1')]
final class BookingOutcomeUnknown extends CommerzException
{
    public function __construct(public readonly string $reference, public readonly string $carrier, Throwable $previous)
    {
        parent::__construct("The outcome of booking '{$reference}' with carrier '{$carrier}' is unknown: {$previous->getMessage()}", previous: $previous);
    }

    public function code(): string
    {
        return 'shipping.booking_outcome_unknown';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Integration;
    }

    public function context(): array
    {
        return ['reference' => $this->reference, 'carrier' => $this->carrier];
    }
}
