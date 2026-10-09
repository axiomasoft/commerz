<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Data;

use Commerz\Attributes\Api;

/** Archives a category without active children; its product assignments stay. Archiving twice changes nothing. */
#[Api(since: '0.1')]
final readonly class ArchiveCategory
{
    public function __construct(public CategoryId $category) {}
}
