<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;

#[Api(since: '0.1')]
final readonly class ReserveStock
{
    /**
     * @param  string  $demand  what holds the stock, e.g. `order:01J…`
     * @param  list<ReserveLine>  $lines  one line per sellable
     * @param  DateTimeImmutable|null  $expiresAt  null holds until released or consumed
     */
    public function __construct(
        public string $demand,
        public ChannelId $channel,
        public array $lines,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        StockLines::assertDemand($demand);
        StockLines::assertUnique($lines);
    }
}
