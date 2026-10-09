<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Shipping\Bookings\Enums\BookingState;

/** Bookings oldest first, optionally in one state, e.g. the `unknown` ones an operator has to sort out. */
#[Api(since: '0.1')]
final readonly class ListBookings
{
    public function __construct(
        public ?BookingState $state = null,
        public ?Cursor $after = null,
        public int $limit = 50,
    ) {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidCommand('limit', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
