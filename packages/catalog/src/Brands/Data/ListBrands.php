<?php

declare(strict_types=1);

namespace Commerz\Catalog\Brands\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Packman\Attributes\Api;

/** Brands ordered by code. */
#[Api(since: '0.1')]
final readonly class ListBrands
{
    public function __construct(
        public bool $includeArchived = false,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
