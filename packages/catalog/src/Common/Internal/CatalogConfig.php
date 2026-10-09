<?php

declare(strict_types=1);

namespace Commerz\Catalog\Common\Internal;

use Commerz\Errors\InvalidConfiguration;

/** The only reader of `config('commerz-catalog.*')`. */
final readonly class CatalogConfig
{
    public function __construct(public int $maxGeneratedVariants) {}

    /** @param  array<array-key, mixed>  $config */
    public static function fromArray(array $config, string $path = 'commerz-catalog'): self
    {
        $max = $config['max_generated_variants'] ?? 100;

        if (! is_int($max) || $max < 1 || $max > 10000) {
            throw new InvalidConfiguration("{$path}.max_generated_variants", 'expected an integer from 1 to 10000');
        }

        return new self($max);
    }
}
