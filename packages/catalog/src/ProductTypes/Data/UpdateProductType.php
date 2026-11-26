<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Catalog\ProductTypes\Exceptions\InvalidProductType;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Renames a type or appends optional slots; axes, kind and existing slots are fixed. */
#[Api(since: '0.1')]
final readonly class UpdateProductType
{
    /**
     * @param  list<string>  $addProductAttributes  new optional product-level slots
     * @param  list<string>  $addVariantAttributes  new optional variant-level slots
     * @param  list<string>  $addOverridableVariantAttributes  new optional overridable-variant slots
     */
    public function __construct(
        public ProductTypeId $type,
        public ?LocalizedText $name = null,
        public array $addProductAttributes = [],
        public array $addVariantAttributes = [],
        public array $addOverridableVariantAttributes = [],
    ) {
        foreach ([
            'addProductAttributes' => $addProductAttributes,
            'addVariantAttributes' => $addVariantAttributes,
            'addOverridableVariantAttributes' => $addOverridableVariantAttributes,
        ] as $field => $codes) {
            foreach ($codes as $code) {
                CatalogCodes::code($field, $code);
            }
        }

        $all = [...$addProductAttributes, ...$addVariantAttributes, ...$addOverridableVariantAttributes];

        if (count(array_unique($all)) !== count($all)) {
            throw new InvalidProductType('an attribute is added twice');
        }
    }
}
