<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: InventoryErrorCode::ReservationConflict,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
