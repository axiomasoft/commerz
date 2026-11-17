<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Events;

use Commerz\Catalog\Products\Data\ProductView;
use Packman\Attributes\Api;

/** Dispatched after the creating transaction commits. */
#[Api(since: '0.1')]
final readonly class ProductCreated
{
    public function __construct(public ProductView $product) {}
}
