<?php

declare(strict_types=1);

namespace Commerz\Inventory\Sources\Data;

use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class StockSourceView
{
    public function __construct(
        public ChannelId $channel,
        public LocationId $location,
        public string $locationCode,
        public int $priority,
    ) {}
}
