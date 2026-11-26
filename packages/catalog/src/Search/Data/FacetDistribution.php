<?php

declare(strict_types=1);

namespace Commerz\Catalog\Search\Data;

use Commerz\Catalog\Search\Enums\FacetKind;
use Packman\Attributes\Api;

/**
 * Facet value distribution for one attribute or variant option axis.
 *
 * For range-capable attributes (`integer`, `decimal`, `measure`, `date`), `min` and `max` hold the canonical string
 * bounds across matching products (never floats).
 */
#[Api(since: '0.1')]
final readonly class FacetDistribution
{
    /**
     * @param  list<FacetValueCount>  $values  ordered by count descending, then value ascending
     */
    public function __construct(
        public string $code,
        public FacetKind $kind,
        public bool $disjunctive,
        public array $values,
        public ?string $min = null,
        public ?string $max = null,
    ) {}

    public function countFor(string $value): int
    {
        foreach ($this->values as $entry) {
            if ($entry->value === $value) {
                return $entry->count;
            }
        }

        return 0;
    }

    /** @return array<string, int> value => product count */
    public function counts(): array
    {
        $map = [];

        foreach ($this->values as $entry) {
            $map[$entry->value] = $entry->count;
        }

        return $map;
    }
}
