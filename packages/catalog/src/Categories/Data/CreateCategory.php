<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Creates a category at the root or under an active parent. */
#[Api(since: '0.1')]
final readonly class CreateCategory
{
    public function __construct(
        public string $code,
        public LocalizedText $name,
        public ?CategoryId $parent = null,
        public int $position = 0,
    ) {
        CatalogCodes::code('code', $code);

        if ($position < 0) {
            throw new InvalidCommand('position', 'must not be negative');
        }
    }
}
