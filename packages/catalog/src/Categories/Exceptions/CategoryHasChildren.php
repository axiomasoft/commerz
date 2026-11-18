<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::CategoryHasChildren,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
