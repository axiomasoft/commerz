<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal;

use Commerz\Catalog\Attributes\Data\AttributeId;

/** Placeholder until the attribute writer owns the projection. */
final readonly class NoopAttributeIndexer implements AttributeIndexer
{
    public function reindexAttribute(AttributeId $attribute): void {}
}
