<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Stores\Data\ChannelId;

#[Api(since: '0.1')]
final readonly class CheckAvailability
{
    /** @param  list<AvailabilityLine>  $lines  one line per sellable */
    public function __construct(public ChannelId $channel, public array $lines)
    {
        StockLines::assertUnique($lines);
    }
}
