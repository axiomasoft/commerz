<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Packman\Attributes\Api;

/** Attributes ordered by code. */
#[Api(since: '0.1')]
final readonly class ListAttributes
{
    public function __construct(
        public ?string $type = null,
        public bool $includeRetired = false,
        public int $first = 50,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
