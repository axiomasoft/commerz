<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class InvalidCollectionOperation extends CommerzException
{
    public function __construct(
        public readonly string $collection,
        public readonly string $reason,
    ) {
        parent::__construct("Invalid operation on collection '{$collection}': {$reason}.");
    }

    public function code(): string
    {
        return 'catalog.invalid_collection_operation';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['collection' => $this->collection, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::InvalidCollectionOperation,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
