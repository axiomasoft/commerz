<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal;

use Commerz\Catalog\Attributes\Data\AttributeId;
use Packman\Attributes\Internal;

/** Rebuilds the filter projection of one attribute; called inside the transaction that changed its definition. */
#[Internal]
interface AttributeIndexer
{
    public function reindexAttribute(AttributeId $attribute): void;
}
