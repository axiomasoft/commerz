<?php

declare(strict_types=1);

namespace Commerz\Catalog\Brands\Contracts;

use Commerz\Catalog\Brands\Data\ArchiveBrand;
use Commerz\Catalog\Brands\Data\BrandId;
use Commerz\Catalog\Brands\Data\BrandView;
use Commerz\Catalog\Brands\Data\CreateBrand;
use Commerz\Catalog\Brands\Data\ListBrands;
use Commerz\Catalog\Brands\Data\UpdateBrand;
use Commerz\Catalog\Brands\Exceptions\BrandArchived;
use Commerz\Catalog\Brands\Exceptions\BrandNotFound;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Pagination\Page;
use Packman\Attributes\Api;

/** Brands: CRUD and cursor-paginated listing. */
#[Api(since: '0.1')]
interface Brands
{
    /** @throws DuplicateCode */
    public function create(CreateBrand $command): BrandView;

    /** @throws BrandNotFound|BrandArchived */
    public function update(UpdateBrand $command): BrandView;

    /** @throws BrandNotFound */
    public function archive(ArchiveBrand $command): BrandView;

    /** @throws BrandNotFound */
    public function find(BrandId $id): BrandView;

    /** @throws BrandNotFound */
    public function findByCode(string $code): BrandView;

    /** @return Page<BrandView> */
    public function list(ListBrands $query): Page;
}
