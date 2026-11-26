<?php

declare(strict_types=1);

namespace Commerz\Catalog\Search\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Catalog\Search\Enums\FacetKind;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Requests value distribution counts for a filterable attribute or variant option axis.
 *
 * When `disjunctive` is true (the default), filters targeting this facet's own code are excluded while computing its
 * distribution so selecting `color=red` does not zero out other colors.
 */
#[Api(since: '0.1')]
final readonly class FacetRequest
{
    public const int MAX_VALUES_LIMIT = 500;

    public string $code;

    public function __construct(
        string $code,
        public FacetKind $kind = FacetKind::Auto,
        public int $maxValues = 100,
        public bool $disjunctive = true,
    ) {
        $this->code = CatalogCodes::code('facets', $code);

        if ($maxValues < 1 || $maxValues > self::MAX_VALUES_LIMIT) {
            throw new InvalidCommand('facets', 'maxValues must be from 1 to '.self::MAX_VALUES_LIMIT, 'catalog.invalid_filter');
        }
    }

    public static function auto(string $code, int $maxValues = 100, bool $disjunctive = true): self
    {
        return new self($code, FacetKind::Auto, $maxValues, $disjunctive);
    }

    public static function attribute(string $code, int $maxValues = 100, bool $disjunctive = true): self
    {
        return new self($code, FacetKind::Attribute, $maxValues, $disjunctive);
    }

    public static function option(string $code, int $maxValues = 100, bool $disjunctive = true): self
    {
        return new self($code, FacetKind::Option, $maxValues, $disjunctive);
    }
}
