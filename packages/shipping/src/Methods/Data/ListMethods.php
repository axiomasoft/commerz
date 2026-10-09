<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Commerz\Shipping\Methods\Enums\MethodStatus;
use Commerz\Shipping\Zones\Data\ZoneId;
use Commerz\Stores\Data\ChannelId;

/** Methods by position, then code; optionally of one status, offered in one channel or priced for one zone. */
#[Api(since: '0.1')]
final readonly class ListMethods
{
    public function __construct(
        public ?MethodStatus $status = null,
        public ?ChannelId $channel = null,
        public ?ZoneId $zone = null,
        public ?Cursor $after = null,
        public int $limit = 50,
    ) {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidCommand('limit', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
