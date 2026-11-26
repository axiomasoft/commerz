<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Replaces the positioned product list of a manual collection. Plain {@see ProductId} items receive sequential
 * positions (0, 1, ...); {@see CollectionItem} entries keep their explicit positions.
 */
#[Api(since: '0.1')]
final readonly class SetCollectionProducts
{
    /** @var list<CollectionItem> */
    public array $items;

    /**
     * @param  array<array-key, ProductId|CollectionItem>  $products
     */
    public function __construct(
        public CollectionId $collection,
        array $products,
    ) {
        if (! array_is_list($products)) {
            throw new InvalidCommand('products', 'must be a list');
        }

        $items = [];
        $seen = [];

        foreach ($products as $index => $entry) {
            $item = $entry instanceof CollectionItem ? $entry : new CollectionItem($entry, $index);

            if (isset($seen[$item->product->value])) {
                throw new InvalidCommand('products', "product '{$item->product->value}' is listed twice");
            }

            $seen[$item->product->value] = true;
            $items[] = $item;
        }

        $this->items = $items;
    }
}
