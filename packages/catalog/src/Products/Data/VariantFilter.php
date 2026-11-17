<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Option values one active variant has together: `axes(['color' => 'red', 'size' => 'm'])` matches a product only
 * when a single variant is both red and M (CAT-03). An unknown option or value matches nothing; it is not an error.
 */
#[Api(since: '0.1')]
final readonly class VariantFilter
{
    /** @param  array<string, string>  $axes  option code => value code */
    private function __construct(public array $axes) {}

    /** @param  array<array-key, mixed>  $axes  option code => value code */
    public static function axes(array $axes): self
    {
        if ($axes === []) {
            throw new InvalidCommand('filters', 'a variant filter needs at least one axis', 'catalog.invalid_filter');
        }

        return new self(CatalogCodes::combination('filters', $axes));
    }
}
