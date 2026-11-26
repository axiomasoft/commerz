<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Data;

use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Batch product upsert command: validates all items upfront, performs bulk `INSERT ... ON CONFLICT (handle) DO UPDATE`,
 * reindexes `cmz_attribute_index` in grouped statements, and emits compressed Outbox events.
 */
#[Api(since: '0.1')]
final readonly class UpsertProducts
{
    public const int MAX_ITEMS = 5000;

    /** @var list<UpsertProductItem> */
    public array $items;

    /**
     * @param  array<array-key, mixed>  $items  list of `UpsertProductItem` with unique handles
     *
     * @throws InvalidCommand|DuplicateCode
     */
    public function __construct(array $items)
    {
        if (! array_is_list($items) || count($items) > self::MAX_ITEMS) {
            throw new InvalidCommand('items', 'must be a list of at most '.self::MAX_ITEMS.' products', 'catalog.invalid_attributes');
        }

        $seenHandles = [];
        $validated = [];

        foreach ($items as $item) {
            if (! $item instanceof UpsertProductItem) {
                throw new InvalidCommand('items', 'every item must be an UpsertProductItem', 'catalog.invalid_attributes');
            }

            if (isset($seenHandles[$item->handle])) {
                throw new DuplicateCode('Product handle', $item->handle);
            }

            $seenHandles[$item->handle] = true;
            $validated[] = $item;
        }

        $this->items = $validated;
    }
}
