<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Creates the variants of the given combinations: the cartesian product of `axes` (in the product's axis order and the
 * given value order) without the `except` combinations. Combinations the product already has are skipped, so a repeated
 * generation is safe. `skuPattern` names each axis as `{optionCode}`, replaced by the value code; every axis with more
 * than one value must appear in it. Generated variants carry no attributes.
 */
#[Api(since: '0.1')]
final readonly class GenerateVariants
{
    /** @var array<string, list<string>> option code => value codes */
    public array $axes;

    /** @var list<array<string, string>> full combinations: option code => value code */
    public array $except;

    /**
     * @param  array<array-key, mixed>  $axes  option code => non-empty list of distinct value codes
     * @param  list<array<array-key, mixed>>  $except  full combinations to leave out
     * @param  bool  $dryRun  plan and check without locking or writing
     */
    public function __construct(
        public ProductId $product,
        array $axes,
        public string $skuPattern,
        array $except = [],
        public bool $dryRun = false,
    ) {
        $normalized = [];

        foreach ($axes as $option => $values) {
            $option = CatalogCodes::code('axes', (string) $option);

            if (! is_array($values) || $values === [] || ! array_is_list($values)) {
                throw new InvalidCommand('axes', "axis '{$option}' needs a non-empty list of value codes", 'catalog.invalid_axes');
            }

            $codes = [];

            foreach ($values as $value) {
                $codes[] = CatalogCodes::code('axes', is_string($value) ? $value : throw new InvalidCommand('axes', "axis '{$option}' lists a value that is not a code", 'catalog.invalid_axes'));
            }

            if (count(array_unique($codes)) !== count($codes)) {
                throw new InvalidCommand('axes', "axis '{$option}' lists a value twice", 'catalog.invalid_axes');
            }

            $normalized[$option] = $codes;
        }

        $this->axes = $normalized;
        $this->except = array_map(static fn (array $combination): array => CatalogCodes::combination('except', $combination), $except);

        if ($skuPattern === '' || strlen($skuPattern) > 191) {
            throw new InvalidCommand('skuPattern', 'must be 1 to 191 bytes', 'catalog.invalid_sku_pattern');
        }
    }
}
