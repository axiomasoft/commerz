<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Adds or repositions specific products inside a manual collection without removing other members. */
#[Api(since: '0.1')]
final readonly class PutCollectionProducts
{
    /** @var non-empty-list<CollectionItem> */
    public array $items;

    /**
     * @param  array<array-key, CollectionItem>  $items
     */
    public function __construct(
        public CollectionId $collection,
        array $items,
    ) {
        if ($items === [] || ! array_is_list($items)) {
            throw new InvalidCommand('items', 'must be a non-empty list of CollectionItem entries');
        }

        $seen = [];

        foreach ($items as $item) {
            if (isset($seen[$item->product->value])) {
                throw new InvalidCommand('items', "product '{$item->product->value}' is listed twice");
            }

            $seen[$item->product->value] = true;
        }

        $this->items = $items;
    }
}
