<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Stores\Data\ChannelId;

#[Api(since: '0.1')]
final readonly class AssignPromotionChannels
{
    /** @param  list<ChannelId>  $channels */
    public function __construct(public PromotionId $promotion, public array $channels)
    {
        if ($channels === []) {
            throw new InvalidCommand('channels', 'must not be empty', 'promotions.invalid_channels');
        }
    }
}
