<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Contracts;

use Commerz\Catalog\Attributes\Exceptions\AttributeNotAllowed;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFilterable;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotWritable;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Exceptions\MissingRequiredAttributes;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Products\Data\ArchiveProduct;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ListProducts;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Data\SetProductAttributes;
use Commerz\Catalog\Products\Data\UpdateProduct;
use Commerz\Catalog\Products\Data\UpsertProducts;
use Commerz\Catalog\Products\Data\UpsertProductsResult;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeArchived;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeNotFound;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Packman\Attributes\Api;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

/**
 * Products: what is sold, described by attributes of its type. Every change is one transaction; a failed attribute
 * value leaves the product, its document and its filter projection unchanged.
 */
#[Api(since: '0.1')]
interface Products
{
    /**
     * @throws ProductTypeNotFound|ProductTypeArchived|DuplicateCode
     * @throws AttributeNotAllowed|AttributeNotWritable|InvalidAttributeValue|MissingRequiredAttributes|UnsupportedContribution
     */
    public function create(CreateProduct $command): ProductView;

    /** @throws ProductNotFound|ProductArchived|DuplicateCode */
    public function update(UpdateProduct $command): ProductView;

    /**
     * @throws ProductNotFound|ProductArchived
     * @throws AttributeNotAllowed|AttributeNotWritable|InvalidAttributeValue|MissingRequiredAttributes|UnsupportedContribution
     */
    public function setAttributes(SetProductAttributes $command): ProductView;

    /** @throws ProductNotFound */
    public function archive(ArchiveProduct $command): ProductView;

    /** @throws ProductNotFound */
    public function find(ProductId $id): ProductView;

    /** @throws ProductNotFound */
    public function findByHandle(string $handle): ProductView;

    /**
     * Batch upserts products by `handle` in one atomic transaction with bounded SQL queries, grouped `cmz_attribute_index`
     * reindexing, and compressed Outbox events.
     *
     * @throws ProductTypeNotFound|ProductTypeArchived|DuplicateCode|InvalidCommand
     * @throws AttributeNotAllowed|AttributeNotWritable|InvalidAttributeValue|MissingRequiredAttributes|UnsupportedContribution
     */
    public function upsertMany(UpsertProducts $command): UpsertProductsResult;

    /**
     * @return Page<ProductView>
     *
     * @throws AttributeNotFound|AttributeNotFilterable|InvalidAttributeValue|InvalidCommand|UnsupportedContribution
     */
    public function list(ListProducts $query): Page;
}
