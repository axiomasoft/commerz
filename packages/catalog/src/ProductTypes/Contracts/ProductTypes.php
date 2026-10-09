<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Contracts;

use Commerz\Attributes\Api;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Options\Exceptions\OptionNotFound;
use Commerz\Catalog\ProductTypes\Data\ArchiveProductType;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\ProductTypes\Data\ListProductTypes;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\ProductTypes\Data\ProductTypeView;
use Commerz\Catalog\ProductTypes\Data\UpdateProductType;
use Commerz\Catalog\ProductTypes\Exceptions\InvalidProductType;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeArchived;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeNotFound;
use Commerz\Errors\UnsupportedContribution;
use Commerz\Pagination\Page;

/** Product types: the attribute slots and variant axes of a family of products. */
#[Api(since: '0.1')]
interface ProductTypes
{
    /**
     * @throws DuplicateCode
     * @throws UnsupportedContribution unknown kind
     * @throws AttributeNotFound
     * @throws OptionNotFound
     * @throws InvalidProductType
     */
    public function create(CreateProductType $command): ProductTypeView;

    /**
     * @throws ProductTypeNotFound
     * @throws ProductTypeArchived
     * @throws AttributeNotFound
     * @throws InvalidProductType
     */
    public function update(UpdateProductType $command): ProductTypeView;

    /** @throws ProductTypeNotFound */
    public function archive(ArchiveProductType $command): ProductTypeView;

    /** @throws ProductTypeNotFound */
    public function find(ProductTypeId $id): ProductTypeView;

    /** @throws ProductTypeNotFound */
    public function findByCode(string $code): ProductTypeView;

    /** @return Page<ProductTypeView> */
    public function list(ListProductTypes $query): Page;
}
