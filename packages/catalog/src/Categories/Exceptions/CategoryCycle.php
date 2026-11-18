<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The new parent is the category itself or one of its descendants: the move would close a cycle. */
#[Api(since: '0.1')]
final class CategoryCycle extends CommerzException
{
    public function __construct(public readonly string $category, public readonly string $parent)
    {
        parent::__construct("Moving category '{$category}' under '{$parent}' would close a cycle.");
    }

    public function code(): string
    {
        return 'catalog.category_cycle';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['category' => $this->category, 'parent' => $this->parent];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::CategoryCycle,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
