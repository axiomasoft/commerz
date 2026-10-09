<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;

/** Categories ordered by code: the children of `parent`, the roots, or (neither) every category. */
#[Api(since: '0.1')]
final readonly class ListCategories
{
    public function __construct(
        public ?CategoryId $parent = null,
        public bool $roots = false,
        public bool $includeArchived = false,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($parent instanceof CategoryId && $roots) {
            throw new InvalidCommand('roots', 'list either the children of a parent or the roots');
        }

        if ($first < 1 || $first > 500) {
            throw new InvalidCommand('first', 'must be from 1 to 500', 'common.invalid_page_size');
        }
    }
}
