<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Sets and removes variant-level attribute values; values not named stay as they are. */
#[Api(since: '0.1')]
final readonly class SetVariantAttributes
{
    /**
     * @param  array<array-key, mixed>  $values  attribute code => value, validated by the attribute's type
     * @param  list<string>  $remove  attribute codes whose values are removed
     */
    public function __construct(
        public VariantId $variant,
        public array $values = [],
        public array $remove = [],
    ) {
        foreach (array_keys($values) as $code) {
            CatalogCodes::code('values', (string) $code);
        }

        foreach ($remove as $code) {
            CatalogCodes::code('remove', $code);
        }

        if ($values === [] && $remove === []) {
            throw new InvalidCommand('values', 'set or remove at least one attribute', 'catalog.invalid_attributes');
        }
    }
}
