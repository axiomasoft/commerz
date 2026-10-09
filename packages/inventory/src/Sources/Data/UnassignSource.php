<?php

declare(strict_types=1);

namespace Commerz\Inventory\Sources\Data;

use Commerz\Attributes\Api;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Stores\Data\ChannelId;

/** Stops the channel selling from the location; a missing pair is not an error. Open reservations keep their stock. */
#[Api(since: '0.1')]
final readonly class UnassignSource
{
    public function __construct(public ChannelId $channel, public LocationId $location) {}
}
