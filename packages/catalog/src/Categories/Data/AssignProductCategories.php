<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Data;

use Commerz\Attributes\Api;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Errors\InvalidCommand;

/** Replaces the categories of a product; their order is the product's order of categories. An empty list removes all. */
#[Api(since: '0.1')]
final readonly class AssignProductCategories
{
    /** @param  list<CategoryId>  $categories  active categories, each once */
    public function __construct(
        public ProductId $product,
        public array $categories,
    ) {
        $ids = array_map(static fn (CategoryId $category): string => $category->value, $categories);

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidCommand('categories', 'a category is listed twice');
        }
    }
}
