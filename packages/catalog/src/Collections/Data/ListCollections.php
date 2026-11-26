<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Commerz\Catalog\Collections\Enums\CollectionKind;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ListCollections
{
    public function __construct(
        public ?CollectionKind $kind = null,
        public bool $includeArchived = false,
        public int $first = 50,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
