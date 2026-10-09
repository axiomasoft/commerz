<?php

declare(strict_types=1);

namespace Commerz\Catalog\Listings\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

/** The product was never published in the channel. */
#[Api(since: '0.1')]
final class ListingNotFound extends NotFound
{
    public function __construct(string $product, string $channel)
    {
        parent::__construct('Listing', "{$product}@{$channel}", 'catalog.listing_not_found');
    }
}
