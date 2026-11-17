<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Variants\Enums\VariantStatus;
use Commerz\Identity\SellableRef;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class VariantView
{
    /**
     * @param  array<string, string>  $axes  option code => value code, in the product's axis order
     * @param  array<array-key, mixed>  $attributes  attribute code => canonical value, ordered by code; retired attributes are left out
     * @param  SellableRef  $sellable  `variant:{id}`, what a cart line references
     */
    public function __construct(
        public VariantId $id,
        public ProductId $product,
        public string $sku,
        public ?string $barcode,
        public array $axes,
        public VariantStatus $status,
        public int $position,
        public array $attributes,
        public ?string $taxClass,
        public ?int $weightGrams,
        public ?Dimensions $dimensions,
        public SellableRef $sellable,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
