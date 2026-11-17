<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ArchiveProductType
{
    public function __construct(public ProductTypeId $type) {}
}
