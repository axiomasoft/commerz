<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Changes the given fields; a null field stays as it is, a field named in `clear` becomes empty. Axes never change. */
#[Api(since: '0.1')]
final readonly class UpdateVariant
{
    /** Fields `clear` may name. */
    public const array CLEARABLE = ['barcode', 'taxClass', 'weightGrams', 'dimensions'];

    /** @param  list<string>  $clear  subset of `CLEARABLE`; a cleared field must not be given a value too */
    public function __construct(
        public VariantId $variant,
        public ?string $sku = null,
        public ?string $barcode = null,
        public ?string $taxClass = null,
        public ?int $weightGrams = null,
        public ?Dimensions $dimensions = null,
        public ?int $position = null,
        public array $clear = [],
    ) {
        if ($sku !== null) {
            CatalogCodes::sku($sku);
        }

        if ($barcode !== null) {
            CatalogCodes::barcode($barcode);
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

        $given = ['barcode' => $barcode, 'taxClass' => $taxClass, 'weightGrams' => $weightGrams, 'dimensions' => $dimensions];

        foreach ($clear as $field) {
            if (! in_array($field, self::CLEARABLE, true)) {
                throw new InvalidCommand('clear', "'{$field}' cannot be cleared; use ".implode(', ', self::CLEARABLE));
            }

            if ($given[$field] !== null) {
                throw new InvalidCommand('clear', "'{$field}' is both set and cleared");
            }
        }
    }
}
