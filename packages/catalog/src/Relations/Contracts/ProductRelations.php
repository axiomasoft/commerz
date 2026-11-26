<?php

declare(strict_types=1);

namespace Commerz\Catalog\Relations\Contracts;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\Relations\Data\AddProductRelation;
use Commerz\Catalog\Relations\Data\ListProductRelations;
use Commerz\Catalog\Relations\Data\ProductRelationView;
use Commerz\Catalog\Relations\Data\RemoveProductRelation;
use Commerz\Catalog\Relations\Data\ReplaceProductRelations;
use Commerz\Catalog\Relations\Enums\ProductRelationType;
use Commerz\Catalog\Relations\Exceptions\ProductRelationNotFound;
use Packman\Attributes\Api;

/** Typed merchandising relations between products (upsell, cross_sell, spare_part, accessory, replacement). */
#[Api(since: '0.1')]
interface ProductRelations
{
    /** @throws ProductNotFound|ProductArchived */
    public function add(AddProductRelation $command): ProductRelationView;

    /** @throws ProductRelationNotFound */
    public function remove(RemoveProductRelation $command): void;

    /**
     * @return list<ProductRelationView>
     *
     * @throws ProductNotFound|ProductArchived
     */
    public function replace(ReplaceProductRelations $command): array;

    /**
     * @return list<ProductRelationView>
     *
     * @throws ProductNotFound
     */
    public function ofProduct(ListProductRelations $query): array;

    /**
     * @return list<ProductRelationView>
     *
     * @throws ProductNotFound
     */
    public function inverseOf(ProductId $targetProduct, ?ProductRelationType $type = null): array;
}
