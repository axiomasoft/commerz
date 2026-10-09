<?php

declare(strict_types=1);

namespace Commerz\Inventory\Sources\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Stores\Data\ChannelId;

/** Lets the channel sell from the location; a lower priority is used first. Assigning an existing pair updates it. */
#[Api(since: '0.1')]
final readonly class AssignSource
{
    public const int MAX_PRIORITY = 1_000_000;

    public function __construct(
        public ChannelId $channel,
        public LocationId $location,
        public int $priority = 100,
    ) {
        if ($priority < 0 || $priority > self::MAX_PRIORITY) {
            throw new InvalidCommand('priority', 'must be from 0 to '.self::MAX_PRIORITY, 'inventory.invalid_priority');
        }
    }
}
