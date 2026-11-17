<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Changes the given fields; a null field stays as it is. The parent changes with `MoveCategory`. */
#[Api(since: '0.1')]
final readonly class UpdateCategory
{
    public function __construct(
        public CategoryId $category,
        public ?LocalizedText $name = null,
        public ?int $position = null,
    ) {
        if ($position !== null && $position < 0) {
            throw new InvalidCommand('position', 'must not be negative');
        }
    }
}
