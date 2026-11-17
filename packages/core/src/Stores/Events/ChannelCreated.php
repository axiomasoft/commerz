<?php

declare(strict_types=1);

namespace Commerz\Stores\Events;

use Commerz\Stores\Data\ChannelView;
use Packman\Attributes\Api;

/** Dispatched after the creating transaction commits. */
#[Api(since: '0.1')]
final readonly class ChannelCreated
{
    public function __construct(public ChannelView $channel) {}
}
