<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Transfers\Enums\TransferState;
use Commerz\Pagination\Cursor;
use Packman\Attributes\Api;

/** Transfers in the order they were created, optionally in one state and/or leaving or entering one location. */
#[Api(since: '0.1')]
final readonly class ListTransfers
{
    public function __construct(
        public ?TransferState $state = null,
        public ?LocationId $location = null,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
