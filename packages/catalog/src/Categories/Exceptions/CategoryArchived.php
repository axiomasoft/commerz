<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The category is archived: it takes no products, no children and no changes. */
#[Api(since: '0.1')]
final class CategoryArchived extends CommerzException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct("Category '{$category}' is archived.");
    }

    public function code(): string
    {
        return 'catalog.category_archived';
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
