<?php

declare(strict_types=1);

namespace Commerz\Catalog\Brands\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ArchiveBrand
{
    public function __construct(
        public BrandId $brand,
    ) {}
}
