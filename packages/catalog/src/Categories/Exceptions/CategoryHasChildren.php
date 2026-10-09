<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A category with active children cannot be archived; move or archive the children first. */
#[Api(since: '0.1')]
final class CategoryHasChildren extends CommerzException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct("Category '{$category}' still has active children.");
    }

    public function code(): string
    {
        return 'catalog.category_has_children';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['category' => $this->category];
    }
}
