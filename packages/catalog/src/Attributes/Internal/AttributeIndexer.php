<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal;

use Commerz\Catalog\Attributes\Data\AttributeId;

/** Rebuilds the filter projection of one attribute; called inside the transaction that changed its definition. */
interface AttributeIndexer
{
    public function reindexAttribute(AttributeId $attribute): void;
}
