<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Package size of a variant in millimetres. */
#[Api(since: '0.1')]
final readonly class Dimensions
{
    public function __construct(
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
    ) {
        if ($lengthMm < 0 || $widthMm < 0 || $heightMm < 0) {
            throw new InvalidCommand('dimensions', 'must not be negative', 'catalog.invalid_dimensions');
        }
    }
}
