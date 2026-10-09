<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Data;

use Commerz\Attributes\Api;
use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Catalog\ProductTypes\Exceptions\InvalidProductType;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;

/** Creates a product type: its kind, the attributes valid on products and variants, and the variant axes. */
#[Api(since: '0.1')]
final readonly class CreateProductType
{
    public const int MAX_AXES = 8;

    /**
     * @param  string  $kind  key of a contributed `product_kind`
     * @param  list<string>  $productAttributes  attribute codes valid on the product, in position order
     * @param  list<string>  $variantAttributes  attribute codes valid on each variant, in position order
     * @param  list<string>  $variantAxes  option codes that create variants, in position order
     * @param  list<string>  $required  attribute codes (of either level) that must be set at creation
     */
    public function __construct(
        public string $code,
        public LocalizedText $name,
        public string $kind = 'physical',
        public array $productAttributes = [],
        public array $variantAttributes = [],
        public array $variantAxes = [],
        public array $required = [],
    ) {
        CatalogCodes::code('code', $code);
        CatalogCodes::code('kind', $kind);

        foreach ([
            'productAttributes' => $productAttributes,
            'variantAttributes' => $variantAttributes,
            'variantAxes' => $variantAxes,
            'required' => $required,
        ] as $field => $codes) {
            foreach ($codes as $item) {
                CatalogCodes::code($field, $item);
            }

            if (count(array_unique($codes)) !== count($codes)) {
                throw new InvalidProductType("{$field} lists a code twice");
            }
        }

        if (count($variantAxes) > self::MAX_AXES) {
            throw new InvalidCommand('variantAxes', 'at most '.self::MAX_AXES.' axes are allowed', 'catalog.too_many_axes');
        }

        $both = array_intersect($productAttributes, $variantAttributes);

        if ($both !== []) {
            throw new InvalidProductType("attribute '".reset($both)."' is on both levels");
        }

        $unknown = array_diff($required, $productAttributes, $variantAttributes);

        if ($unknown !== []) {
            throw new InvalidProductType("required attribute '".reset($unknown)."' has no slot");
        }
    }
}
