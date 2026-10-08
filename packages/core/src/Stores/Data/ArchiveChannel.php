<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Attributes\Api;

/** Archived channels stay readable but cannot start new contexts. Archiving twice is a no-op. */
#[Api(since: '0.1')]
final readonly class ArchiveChannel
{
    public function __construct(public ChannelId $channel) {}
}
