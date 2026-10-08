<?php

declare(strict_types=1);

namespace Commerz\Stores\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class ChannelNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Channel', $id, 'stores.channel_not_found');
    }
}
