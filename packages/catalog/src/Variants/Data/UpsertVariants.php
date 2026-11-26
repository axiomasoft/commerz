<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Batch variant upsert command: validates all SKUs upfront, performs bulk `INSERT ... ON CONFLICT (sku) DO UPDATE`,
 * reindexes `cmz_attribute_index` in grouped statements, and emits compressed Outbox events.
 */
#[Api(since: '0.1')]
final readonly class UpsertVariants
{
    public const int MAX_ITEMS = 5000;

    /** @var list<UpsertVariantItem> */
    public array $items;

    /**
     * @param  array<array-key, mixed>  $items  list of `UpsertVariantItem` with unique SKUs and barcodes
     *
     * @throws InvalidCommand|DuplicateCode
     */
    public function __construct(array $items)
    {
        if (! array_is_list($items) || count($items) > self::MAX_ITEMS) {
            throw new InvalidCommand('items', 'must be a list of at most '.self::MAX_ITEMS.' variants', 'catalog.invalid_attributes');
        }

        $seenSkus = [];
        $seenBarcodes = [];
        $validated = [];

        foreach ($items as $item) {
            if (! $item instanceof UpsertVariantItem) {
                throw new InvalidCommand('items', 'every item must be an UpsertVariantItem', 'catalog.invalid_attributes');
            }

            if (isset($seenSkus[$item->sku])) {
                throw new DuplicateCode('Variant SKU', $item->sku);
            }

            $seenSkus[$item->sku] = true;

            if ($item->barcode !== null) {
                if (isset($seenBarcodes[$item->barcode])) {
                    throw new DuplicateCode('Variant barcode', $item->barcode);
                }

                $seenBarcodes[$item->barcode] = true;
            }

            $validated[] = $item;
        }

        $this->items = $validated;
    }
}
