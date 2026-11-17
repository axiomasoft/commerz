<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Creates one variant of a product. `axes` names a value of every axis of the product, and nothing else;
 * a product without axes has exactly one variant, created with no axes.
 */
#[Api(since: '0.1')]
final readonly class CreateVariant
{
    /** @var array<string, string> option code => value code */
    public array $axes;

    /**
     * @param  array<array-key, mixed>  $axes  option code => value code
     * @param  array<array-key, mixed>  $attributes  variant-level attribute code => value, validated by the attribute's type
     * @param  int|null  $position  null puts the variant after the last one of the product
     */
    public function __construct(
        public ProductId $product,
        public string $sku,
        array $axes = [],
        public ?string $barcode = null,
        public array $attributes = [],
        public ?string $taxClass = null,
        public ?int $weightGrams = null,
        public ?Dimensions $dimensions = null,
        public ?int $position = null,
    ) {
        CatalogCodes::sku($sku);
        $this->axes = CatalogCodes::combination('axes', $axes);

        if ($barcode !== null) {
            CatalogCodes::barcode($barcode);
        }

        foreach (array_keys($attributes) as $code) {
            CatalogCodes::code('attributes', (string) $code);
        }

        if ($taxClass !== null) {
            CatalogCodes::code('taxClass', $taxClass);
        }

        if ($weightGrams !== null && $weightGrams < 0) {
            throw new InvalidCommand('weightGrams', 'must not be negative', 'catalog.invalid_dimensions');
        }

        if ($position !== null && $position < 0) {
            throw new InvalidCommand('position', 'must not be negative');
        }
    }
}
