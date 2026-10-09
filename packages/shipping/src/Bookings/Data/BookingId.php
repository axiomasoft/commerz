<?php

declare(strict_types=1);

namespace Commerz\Shipping\Bookings\Data;

use Commerz\Attributes\Api;
use Commerz\Identity\EntityId;

#[Api(since: '0.1')]
final readonly class BookingId extends EntityId {}
