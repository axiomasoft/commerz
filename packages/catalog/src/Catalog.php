<?php

declare(strict_types=1);

namespace Commerz\Catalog;

use Commerz\Attributes\Api;
use Commerz\Catalog\Attributes\Contracts\Attributes;
use Commerz\Catalog\Options\Contracts\Options;

/** Root of the catalog API: accessors of the module contracts. */
#[Api(since: '0.1')]
final readonly class Catalog
{
    public function __construct(
        private Options $options,
        private Attributes $attributes,
    ) {}

    public function options(): Options
    {
        return $this->options;
    }

    public function attributes(): Attributes
    {
        return $this->attributes;
    }
}
