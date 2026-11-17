<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** The product is archived and takes no further changes. */
#[Api(since: '0.1')]
final class ProductArchived extends CommerzException
{
    public function __construct(public readonly string $product)
    {
        parent::__construct("Product '{$product}' is archived.");
    }

    public function code(): string
    {
        return 'catalog.product_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['product' => $this->product];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::ProductArchived,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}
