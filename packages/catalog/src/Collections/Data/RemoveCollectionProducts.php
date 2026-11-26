<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Removes products from a manual collection. */
#[Api(since: '0.1')]
final readonly class RemoveCollectionProducts
{
    /** @var non-empty-list<ProductId> */
    public array $products;

    /**
     * @param  array<array-key, ProductId>  $products
     */
    public function __construct(
        public CollectionId $collection,
        array $products,
    ) {
        if ($products === [] || ! array_is_list($products)) {
            throw new InvalidCommand('products', 'must be a non-empty list of ProductId');
        }

        $unique = [];
        $seen = [];

        foreach ($products as $product) {
            if (! isset($seen[$product->value])) {
                $seen[$product->value] = true;
                $unique[] = $product;
            }
        }

        $this->products = $unique;
    }
}
