<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Data;

use Commerz\Attributes\Api;
use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;

/** Defines an attribute; its type and config cannot change afterwards. */
#[Api(since: '0.1')]
final readonly class DefineAttribute
{
    /**
     * @param  string  $type  key of a contributed `attribute_type`
     * @param  array<string, mixed>  $config  raw config, canonicalized by the type
     * @param  array<array-key, LocalizedText>  $choices  code => label, in position order
     */
    public function __construct(
        public string $code,
        public string $type,
        public LocalizedText $name,
        public array $config = [],
        public array $choices = [],
        public bool $filterable = false,
    ) {
        CatalogCodes::code('code', $code);

        if (preg_match('/\A[a-z0-9][a-z0-9_.\-]{0,63}\z/', $type) !== 1) {
            throw new InvalidCommand('type', "'{$type}' is not a type key", 'catalog.invalid_code');
        }

        foreach ($choices as $choiceCode => $label) {
            CatalogCodes::code('choices', (string) $choiceCode);
        }
    }
}
