<?php

declare(strict_types=1);

namespace Commerz\Catalog\Listings\Events;

use Commerz\Attributes\Api;
use Commerz\Catalog\Listings\Data\ListingView;

/** Dispatched after a product was taken out of a channel and the change committed. */
#[Api(since: '0.1')]
final readonly class ProductUnpublished
{
    public function __construct(public ListingView $listing) {}
}
