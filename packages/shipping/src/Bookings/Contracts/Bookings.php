<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Contracts;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Commerz\Shipping\Bookings\Data\BookingView;
use Commerz\Shipping\Bookings\Data\ListBookings;
use Commerz\Shipping\Bookings\Exceptions\BookingNotFound;
use Packman\Attributes\Api;

/** The booking journal, read-only: shipments are booked through the kernel port `ShipmentBooking`. */
#[Api(since: '0.1')]
interface Bookings
{
    /** @throws BookingNotFound */
    public function find(string $reference): BookingView;

    /**
     * @return Page<BookingView>
     *
     * @throws InvalidCommand common.invalid_cursor
     */
    public function list(ListBookings $query): Page;
}
