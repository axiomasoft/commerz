<?php

declare(strict_types=1);

namespace Commerz\Inventory\Locations\Contracts;

use Commerz\Inventory\Common\Exceptions\DuplicateCode;
use Commerz\Inventory\Locations\Data\ArchiveLocation;
use Commerz\Inventory\Locations\Data\CreateLocation;
use Commerz\Inventory\Locations\Data\ListLocations;
use Commerz\Inventory\Locations\Data\LocationId;
use Commerz\Inventory\Locations\Data\LocationView;
use Commerz\Inventory\Locations\Data\RestoreLocation;
use Commerz\Inventory\Locations\Data\UpdateLocation;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotEmpty;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Pagination\Page;
use Packman\Attributes\Api;

/** Places that hold stock: warehouses, stores, pickup points. */
#[Api(since: '0.1')]
interface Locations
{
    /** @throws DuplicateCode */
    public function create(CreateLocation $command): LocationView;

    /** @throws LocationNotFound|LocationArchived */
    public function update(UpdateLocation $command): LocationView;

    /** @throws LocationNotFound|LocationNotEmpty */
    public function archive(ArchiveLocation $command): LocationView;

    /** @throws LocationNotFound */
    public function restore(RestoreLocation $command): LocationView;

    /** @throws LocationNotFound */
    public function find(LocationId $id): LocationView;

    /** @throws LocationNotFound */
    public function findByCode(string $code): LocationView;

    /** @return Page<LocationView> */
    public function list(ListLocations $query): Page;
}
