<?php

declare(strict_types=1);

namespace Commerz\Catalog\Listings\Events;

use Commerz\Catalog\Listings\Data\ListingView;
use Packman\Attributes\Api;

/** Dispatched after a publication (a new one or a changed window) committed. */
#[Api(since: '0.1')]
final readonly class ProductPublished
{
    public function __construct(public ListingView $listing) {}
}
