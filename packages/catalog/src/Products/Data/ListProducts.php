<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Data;

use Commerz\Attributes\Api;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Cursor;

/** Products ordered by handle; a null filter does not restrict (every status is listed unless one is given). */
#[Api(since: '0.1')]
final readonly class ListProducts
{
    public function __construct(
        public ?ProductTypeId $type = null,
        public ?ProductStatus $status = null,
        public int $first = 24,
        public ?Cursor $after = null,
    ) {
        if ($first < 1 || $first > 200) {
            throw new InvalidCommand('first', 'must be from 1 to 200', 'common.invalid_page_size');
        }
    }
}
