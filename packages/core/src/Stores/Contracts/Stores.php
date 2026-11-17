<?php

declare(strict_types=1);

namespace Commerz\Stores\Contracts;

use Commerz\Pagination\Page;
use Commerz\Stores\Data\ArchiveStore;
use Commerz\Stores\Data\CreateStore;
use Commerz\Stores\Data\ListStores;
use Commerz\Stores\Data\StoreId;
use Commerz\Stores\Data\StoreView;
use Commerz\Stores\Data\UpdateStore;
use Commerz\Stores\Exceptions\DuplicateCode;
use Commerz\Stores\Exceptions\StoreNotFound;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
interface Stores
{
    /** @throws DuplicateCode */
    public function create(CreateStore $command): StoreView;

    /** @throws StoreNotFound */
    public function update(UpdateStore $command): StoreView;

    /** @throws StoreNotFound */
    public function archive(ArchiveStore $command): StoreView;

    /** @throws StoreNotFound */
    public function find(StoreId $id): StoreView;

    /** @return Page<StoreView> */
    public function list(ListStores $query): Page;
}
