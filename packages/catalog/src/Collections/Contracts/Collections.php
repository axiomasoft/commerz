<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Contracts;

use Commerz\Catalog\Collections\Data\ArchiveCollection;
use Commerz\Catalog\Collections\Data\CollectionId;
use Commerz\Catalog\Collections\Data\CollectionProductView;
use Commerz\Catalog\Collections\Data\CollectionView;
use Commerz\Catalog\Collections\Data\CreateCollection;
use Commerz\Catalog\Collections\Data\ListCollectionProducts;
use Commerz\Catalog\Collections\Data\ListCollections;
use Commerz\Catalog\Collections\Data\PutCollectionProducts;
use Commerz\Catalog\Collections\Data\RemoveCollectionProducts;
use Commerz\Catalog\Collections\Data\SetCollectionProducts;
use Commerz\Catalog\Collections\Data\UpdateCollection;
use Commerz\Catalog\Collections\Exceptions\CollectionArchived;
use Commerz\Catalog\Collections\Exceptions\CollectionNotFound;
use Commerz\Catalog\Collections\Exceptions\InvalidCollectionOperation;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Products\Data\AttributeFilter;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\VariantFilter;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Pagination\Page;
use Packman\Attributes\Api;

/**
 * Curated manual collections with explicit product positioning and dynamic smart collections backed by serialized
 * {@see AttributeFilter} and {@see VariantFilter} rules.
 */
#[Api(since: '0.1')]
interface Collections
{
    /** @throws DuplicateCode */
    public function create(CreateCollection $command): CollectionView;

    /** @throws CollectionNotFound|CollectionArchived|InvalidCollectionOperation */
    public function update(UpdateCollection $command): CollectionView;

    /** @throws CollectionNotFound */
    public function archive(ArchiveCollection $command): CollectionView;

    /** @throws CollectionNotFound */
    public function find(CollectionId $id): CollectionView;

    /** @throws CollectionNotFound */
    public function findByCode(string $code): CollectionView;

    /** @return Page<CollectionView> */
    public function list(ListCollections $query): Page;

    /**
     * Replaces all members of a manual collection and returns them ordered by `(position, handle)`.
     *
     * @return list<CollectionProductView>
     *
     * @throws CollectionNotFound|CollectionArchived|InvalidCollectionOperation|ProductNotFound
     */
    public function setProducts(SetCollectionProducts $command): array;

    /**
     * Inserts or repositions specific products in a manual collection and returns all members ordered by `(position, handle)`.
     *
     * @return list<CollectionProductView>
     *
     * @throws CollectionNotFound|CollectionArchived|InvalidCollectionOperation|ProductNotFound
     */
    public function putProducts(PutCollectionProducts $command): array;

    /**
     * Removes specific products from a manual collection.
     *
     * @throws CollectionNotFound|CollectionArchived|InvalidCollectionOperation
     */
    public function removeProducts(RemoveCollectionProducts $command): void;

    /**
     * Resolves paginated products of a manual (by `position`, then `handle`) or smart (by `handle`) collection.
     *
     * @return Page<CollectionProductView>
     *
     * @throws CollectionNotFound
     */
    public function products(ListCollectionProducts $query): Page;

    /**
     * Active manual collections explicitly containing the given product, ordered by `code`.
     *
     * @return list<CollectionView>
     *
     * @throws ProductNotFound
     */
    public function ofProduct(ProductId $product): array;
}
