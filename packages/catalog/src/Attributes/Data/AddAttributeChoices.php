<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Data;

use Commerz\Attributes\Api;
use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;

/** Appends choices after the existing ones. */
#[Api(since: '0.1')]
final readonly class AddAttributeChoices
{
    /** @param  array<array-key, LocalizedText>  $choices  code => label, at least one */
    public function __construct(public AttributeId $attribute, public array $choices)
    {
        if ($choices === []) {
            throw new InvalidCommand('choices', 'at least one choice is required', 'catalog.invalid_choices');
        }

        foreach ($choices as $code => $label) {
            CatalogCodes::code('choices', (string) $code);
        }
    }
}
