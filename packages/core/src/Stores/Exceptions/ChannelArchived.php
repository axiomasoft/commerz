<?php

declare(strict_types=1);

namespace Commerz\Stores\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** An archived channel was used where an active one is required (a new context, a channel update). */
#[Api(since: '0.1')]
final class ChannelArchived extends CommerzException
{
    public function __construct(public readonly string $channel)
    {
        parent::__construct("Channel '{$channel}' is archived.");
    }

    public function code(): string
    {
        return 'stores.channel_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['channel' => $this->channel];
    }
}
