<?php

declare(strict_types=1);

namespace Commerz\Pricing\PriceLists\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Stores\Data\ChannelId;

#[Api(since: '0.1')]
final readonly class AssignPriceListChannels
{
    /** @param  list<ChannelId>  $channels */
    public function __construct(public PriceListId $priceList, public array $channels)
    {
        if ($channels === []) {
            throw new InvalidCommand('channels', 'must not be empty', 'pricing.invalid_channels');
        }
    }
}
