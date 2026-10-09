<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Events;

use Commerz\Attributes\Api;
use Commerz\Catalog\Products\Data\ProductView;

/** Dispatched after a product changed (fields, attributes or archival) and the change committed; the view is the state after it. */
#[Api(since: '0.1')]
final readonly class ProductUpdated
{
    public function __construct(public ProductView $product) {}
}
