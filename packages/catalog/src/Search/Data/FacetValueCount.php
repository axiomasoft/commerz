<?php

declare(strict_types=1);

namespace Commerz\Catalog\Search\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** One bucket in a facet distribution: a canonical value, the distinct product count, and selection state. */
#[Api(since: '0.1')]
final readonly class FacetValueCount
{
    public function __construct(
        public string $value,
        public int $count,
        public bool $selected = false,
        public ?LocalizedText $label = null,
    ) {
        if ($count < 0) {
            throw new InvalidCommand('count', 'facet count must not be negative', 'catalog.invalid_filter');
        }
    }
}
