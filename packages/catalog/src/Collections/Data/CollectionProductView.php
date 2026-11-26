<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Commerz\Catalog\Products\Data\ProductView;
use Packman\Attributes\Api;

/** A product resolved from a collection, carrying its explicit position in manual collections (null for smart collections). */
#[Api(since: '0.1')]
final readonly class CollectionProductView
{
    public function __construct(
        public ProductView $product,
        public ?int $position,
    ) {}
}
