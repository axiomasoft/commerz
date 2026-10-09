<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Data;

use Commerz\Attributes\Api;

/** Moves a category with its subtree under another active category, or to the root when `parent` is null. */
#[Api(since: '0.1')]
final readonly class MoveCategory
{
    public function __construct(
        public CategoryId $category,
        public ?CategoryId $parent,
    ) {}
}
