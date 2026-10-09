<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Contracts;

use Commerz\Attributes\Api;
use Commerz\Catalog\Categories\Data\ArchiveCategory;
use Commerz\Catalog\Categories\Data\AssignProductCategories;
use Commerz\Catalog\Categories\Data\CategoryId;
use Commerz\Catalog\Categories\Data\CategoryView;
use Commerz\Catalog\Categories\Data\CreateCategory;
use Commerz\Catalog\Categories\Data\ListCategories;
use Commerz\Catalog\Categories\Data\MoveCategory;
use Commerz\Catalog\Categories\Data\UpdateCategory;
use Commerz\Catalog\Categories\Exceptions\CategoryArchived;
use Commerz\Catalog\Categories\Exceptions\CategoryCycle;
use Commerz\Catalog\Categories\Exceptions\CategoryHasChildren;
use Commerz\Catalog\Categories\Exceptions\CategoryNotFound;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Pagination\Page;

/** Categories: a tree for navigation that stays acyclic under concurrent moves; products belong to any number of them. */
#[Api(since: '0.1')]
interface Categories
{
    /** @throws DuplicateCode|CategoryNotFound|CategoryArchived the parent is missing or archived */
    public function create(CreateCategory $command): CategoryView;

    /** @throws CategoryNotFound|CategoryArchived */
    public function update(UpdateCategory $command): CategoryView;

    /** @throws CategoryNotFound|CategoryArchived|CategoryCycle */
    public function move(MoveCategory $command): CategoryView;

    /** @throws CategoryNotFound|CategoryHasChildren */
    public function archive(ArchiveCategory $command): CategoryView;

    /** @throws CategoryNotFound */
    public function find(CategoryId $id): CategoryView;

    /** @throws CategoryNotFound */
    public function findByCode(string $code): CategoryView;

    /** @return Page<CategoryView> */
    public function list(ListCategories $query): Page;

    /**
     * @return list<CategoryView> the product's categories in their new order
     *
     * @throws ProductNotFound|CategoryNotFound|CategoryArchived
     */
    public function assign(AssignProductCategories $command): array;

    /**
     * @return list<CategoryView> in the product's order of categories
     *
     * @throws ProductNotFound
     */
    public function ofProduct(ProductId $product): array;
}
