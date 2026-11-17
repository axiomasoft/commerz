<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Creates a product of an active type; the type's variant axes are copied onto it. */
#[Api(since: '0.1')]
final readonly class CreateProduct
{
    /** @param  array<array-key, mixed>  $attributes  product-level attribute code => value, validated by the attribute's type */
    public function __construct(
        public ProductTypeId $type,
        public string $handle,
        public LocalizedText $name,
        public ?LocalizedText $description = null,
        public array $attributes = [],
        public ProductStatus $status = ProductStatus::Active,
    ) {
        CatalogCodes::handle($handle);

        foreach (array_keys($attributes) as $code) {
            CatalogCodes::code('attributes', (string) $code);
        }

        if ($status === ProductStatus::Archived) {
            throw new InvalidCommand('status', 'a product cannot be created archived', 'catalog.invalid_status_transition');
        }
    }
}
