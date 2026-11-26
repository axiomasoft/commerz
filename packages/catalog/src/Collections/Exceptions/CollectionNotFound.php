<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\NotFound;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class CollectionNotFound extends NotFound
{
    /** @param  string  $reference  a collection id or code */
    public function __construct(string $reference)
    {
        parent::__construct('Collection', $reference, 'catalog.collection_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::CollectionNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}
