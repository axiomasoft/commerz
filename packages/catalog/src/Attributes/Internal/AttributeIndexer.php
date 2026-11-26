<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Internal;

use Commerz\Catalog\Attributes\Data\AttributeId;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Variants\Data\VariantId;
use Packman\Attributes\Internal;

/** Rebuilds the filter projection in `attribute_index` inside the caller's transaction. */
#[Internal]
interface AttributeIndexer
{
    public function reindexAttribute(AttributeId $attribute): void;

    public function reindexProduct(ProductId $product): void;

    public function reindexVariant(VariantId $variant): void;
}
