<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;

/** Product types ordered by code. */
#[Api(since: '0.1')]
final readonly class ListProductTypes
{
    public function __construct(
        public bool $includeArchived = false,
        public int $first = 50,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
