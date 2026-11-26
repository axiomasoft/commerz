<?php

declare(strict_types=1);

namespace Commerz\Catalog\Brands\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The brand is archived and cannot be modified. */
#[Api(since: '0.1')]
final class BrandArchived extends CommerzException
{
    public function __construct(public readonly string $brand)
    {
        parent::__construct("Brand '{$brand}' is archived.");
    }

    public function code(): string
    {
        return 'catalog.brand_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['brand' => $this->brand];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::BrandArchived,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
