<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class UnassignMethodChannels
{
    /** @param  list<ChannelId>  $channels */
    public function __construct(public MethodId $id, public array $channels)
    {
        if ($channels === []) {
            throw new InvalidCommand('channels', 'must not be empty', 'shipping.invalid_channels');
        }
    }
}
