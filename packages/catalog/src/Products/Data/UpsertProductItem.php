<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/**
 * One product entry for `Products::upsertMany()`, matched on its unique `handle`.
 *
 * When `replaceAttributes` is false (default), supplied `attributes` are merged onto an existing product's stored
 * attributes; when true, stored attributes are replaced by the supplied map.
 */
#[Api(since: '0.1')]
final readonly class UpsertProductItem
{
    /** @param  array<array-key, mixed>  $attributes  product-level attribute code => value */
    public function __construct(
        public ProductTypeId $type,
        public string $handle,
        public LocalizedText $name,
        public ?LocalizedText $description = null,
        public array $attributes = [],
        public ProductStatus $status = ProductStatus::Active,
        public bool $replaceAttributes = false,
    ) {
        CatalogCodes::handle($handle);

        foreach (array_keys($attributes) as $code) {
            CatalogCodes::code('attributes', (string) $code);
        }
    }
}
