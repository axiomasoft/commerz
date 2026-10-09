<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Data;

use Commerz\Attributes\Api;
use Commerz\Stores\Data\ChannelId;

#[Api(since: '0.1')]
final readonly class RestockStock
{
    /**
     * @param  string  $reference  the document that returns the stock, e.g. `return:01J…`; restocking it twice is a no-op
     * @param  list<RestockLine>  $lines  one line per sellable
     * @param  string|null  $locationCode  puts the units back into this location; null lets the stock implementation choose
     */
    public function __construct(
        public string $reference,
        public ChannelId $channel,
        public array $lines,
        public ?string $locationCode = null,
    ) {
        StockLines::assertReference($reference);
        StockLines::assertUnique($lines);
        StockLines::assertLocation($locationCode);
    }
}
