<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Defines a variant axis with its values. */
#[Api(since: '0.1')]
final readonly class DefineOption
{
    /** @param  array<array-key, LocalizedText>  $values  code => label, in position order, at least one */
    public function __construct(
        public string $code,
        public LocalizedText $name,
        public array $values,
    ) {
        CatalogCodes::code('code', $code);

        if ($values === []) {
            throw new InvalidCommand('values', 'at least one value is required', 'catalog.invalid_option_values');
        }

        foreach ($values as $valueCode => $label) {
            CatalogCodes::code('values', (string) $valueCode);
        }
    }
}
