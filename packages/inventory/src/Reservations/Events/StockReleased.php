<?php

declare(strict_types=1);

namespace Commerz\Inventory\Reservations\Events;

use Commerz\Inventory\Reservations\Enums\ReleaseCause;
use Packman\Attributes\Api;

/** Held stock of a demand went back to free stock in a committed operation. */
#[Api(since: '0.1')]
final readonly class StockReleased
{
    /** @param  array<string, int>  $units  sellable ref => sellable units given back */
    public function __construct(
        public string $demand,
        public ReleaseCause $cause,
        public array $units,
    ) {}
}
