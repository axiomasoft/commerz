<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Locations\Enums\LocationStatus;
use Commerz\Pagination\Cursor;
use Packman\Attributes\Api;

/** Locations ordered by code, optionally of one status. */
#[Api(since: '0.1')]
final readonly class ListLocations
{
    public function __construct(
        public ?LocationStatus $status = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
