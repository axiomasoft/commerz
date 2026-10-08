<?php

declare(strict_types=1);

namespace Commerz\Stores\Events;

use Commerz\Attributes\Api;
use Commerz\Stores\Data\ChannelView;

/** Dispatched after a channel changed or was archived; the view is the state after the change. */
#[Api(since: '0.1')]
final readonly class ChannelUpdated
{
    public function __construct(public ChannelView $channel) {}
}
