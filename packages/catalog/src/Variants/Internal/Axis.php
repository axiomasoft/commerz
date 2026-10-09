<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Internal;

/** One variant axis of a product with the values its option offers. */
final readonly class Axis
{
    /** @param  array<string, string>  $values  value code => option value id */
    public function __construct(
        public string $option,
        public string $code,
        public array $values,
    ) {}
}
