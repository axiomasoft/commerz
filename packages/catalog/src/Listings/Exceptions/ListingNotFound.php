<?php

declare(strict_types=1);

namespace Commerz\Catalog\Listings\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\NotFound;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** The product was never published in the channel. */
#[Api(since: '0.1')]
final class ListingNotFound extends NotFound
{
    public function __construct(string $product, string $channel)
    {
        parent::__construct('Listing', "{$product}@{$channel}", 'catalog.listing_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::ListingNotFound,
            category: PackmanErrorCategory::NotFound,
            retryable: false,
            details: $this->context(),
        );
    }
}
