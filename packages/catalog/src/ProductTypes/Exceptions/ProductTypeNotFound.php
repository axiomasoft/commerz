<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\NotFound;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

#[Api(since: '0.1')]
final class ProductTypeNotFound extends NotFound
{
    /** @param  string  $reference  a product type id or code */
    public function __construct(string $reference)
    {
        parent::__construct('Product type', $reference, 'catalog.product_type_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::ProductTypeNotFound,
            category: PackmanErrorCategory::NotFound,
            retryable: false,
            details: $this->context(),
        );
    }
}
