<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Closes up to `$limit` reservations whose `expiresAt` has passed; null takes `commerz-inventory.expiry_batch`. */
#[Api(since: '0.1')]
final readonly class ExpireReservations
{
    public function __construct(public ?int $limit = null)
    {
        if ($limit !== null && ($limit < 1 || $limit > 10000)) {
            throw new InvalidCommand('limit', 'must be from 1 to 10000', 'inventory.invalid_limit');
        }
    }
}
