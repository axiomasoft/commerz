<?php

declare(strict_types=1);

namespace Commerz\Catalog\Projection\Data;

use Commerz\Catalog\Variants\Data\VariantId;
use Packman\Attributes\Api;

/** Denormalized variant snapshot embedded inside a single storefront product row. */
#[Api(since: '0.1')]
final readonly class StorefrontVariantView
{
    /**
     * @param  array<string, string>  $options  localized option name => localized value label
     * @param  array<string, mixed>  $effectiveAttributes  attribute code => effective canonical value (variant override or product fallback)
     */
    public function __construct(
        public VariantId $id,
        public string $sku,
        public array $options,
        public array $effectiveAttributes,
        public ?int $priceMinor,
        public ?int $compareAtMinor,
        public ?string $currency,
        public bool $taxIncluded,
        public bool $inStock,
        public int $position,
    ) {}
}
