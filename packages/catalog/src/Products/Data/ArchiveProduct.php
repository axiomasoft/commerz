<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Data;

use Commerz\Attributes\Api;

/** Archives a product; archiving an archived product changes nothing. */
#[Api(since: '0.1')]
final readonly class ArchiveProduct
{
    public function __construct(public ProductId $product) {}
}
