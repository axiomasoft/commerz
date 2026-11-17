<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Packman\Attributes\Api;

/** One combination of a generation and the SKU it gets. */
#[Api(since: '0.1')]
final readonly class PlannedVariant
{
    /** @param  array<string, string>  $axes  option code => value code, in the product's axis order */
    public function __construct(
        public array $axes,
        public string $sku,
    ) {}
}
