<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Data;

use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Measures\LocalizedText;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ProductView
{
    /**
     * @param  array<array-key, mixed>  $attributes  attribute code => canonical value, ordered by code; retired attributes are left out,
     *                                               values of types no plugin contributes any more are returned as stored
     * @param  list<string>  $axes  option codes of the product's variant axes, by position
     */
    public function __construct(
        public ProductId $id,
        public ProductTypeId $type,
        public string $handle,
        public LocalizedText $name,
        public ?LocalizedText $description,
        public ProductStatus $status,
        public array $attributes,
        public array $axes,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
