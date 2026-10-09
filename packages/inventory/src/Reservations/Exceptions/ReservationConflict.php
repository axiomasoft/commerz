<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The demand already holds stock for another channel; a demand belongs to one channel. */
#[Api(since: '0.1')]
final class ReservationConflict extends CommerzException
{
    public function __construct(public readonly string $demand, public readonly string $channel)
    {
        parent::__construct("Demand '{$demand}' is reserved for channel '{$channel}'.");
    }

    public function code(): string
    {
        return 'inventory.reservation_conflict';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['demand' => $this->demand, 'channel' => $this->channel];
    }
}
