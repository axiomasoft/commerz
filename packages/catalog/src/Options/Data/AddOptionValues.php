<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Data;

use Commerz\Attributes\Api;
use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;

/** Appends values after the existing ones. */
#[Api(since: '0.1')]
final readonly class AddOptionValues
{
    /** @param  array<array-key, LocalizedText>  $values  code => label, at least one */
    public function __construct(public OptionId $option, public array $values)
    {
        if ($values === []) {
            throw new InvalidCommand('values', 'at least one value is required', 'catalog.invalid_option_values');
        }

        foreach ($values as $code => $label) {
            CatalogCodes::code('values', (string) $code);
        }
    }
}
