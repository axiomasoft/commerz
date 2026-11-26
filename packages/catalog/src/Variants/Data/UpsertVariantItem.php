<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Variants\Enums\VariantStatus;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * One variant entry for `Variants::upsertMany()`, matched on its unique `sku`.
 *
 * Axes must match the product's axes (and cannot change for an existing SKU). When `replaceAttributes` is false
 * (default), supplied `attributes` are merged onto an existing variant's stored attributes.
 */
#[Api(since: '0.1')]
final readonly class UpsertVariantItem
{
    /** @var array<string, string> option code => value code */
    public array $axes;

    /**
     * @param  array<array-key, mixed>  $axes  option code => value code
     * @param  array<array-key, mixed>  $attributes  variant-level attribute code => value
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
        public VariantStatus $status = VariantStatus::Active,
        public bool $replaceAttributes = false,
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
