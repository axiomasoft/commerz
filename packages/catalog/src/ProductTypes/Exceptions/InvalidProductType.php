<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The slots or axes of a product type are inconsistent. */
#[Api(since: '0.1')]
final class InvalidProductType extends CommerzException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid product type: {$reason}.");
    }

    public function code(): string
    {
        return 'catalog.invalid_product_type';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::InvalidProductType,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->context(),
        );
    }
}
