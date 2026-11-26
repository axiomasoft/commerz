<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ArchiveCollection
{
    public function __construct(public CollectionId $collection) {}
}
