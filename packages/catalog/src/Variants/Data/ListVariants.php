<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Commerz\Attributes\Api;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;

/** Variants of one product ordered by position, then id. */
#[Api(since: '0.1')]
final readonly class ListVariants
{
    public function __construct(
        public ProductId $product,
        public bool $includeArchived = false,
        public int $first = 100,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 500) {
            throw new InvalidCommand('first', 'must be from 1 to 500', 'common.invalid_page_size');
        }
    }
}
