<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Commerz\Shipping\Common\Exceptions\DuplicateCode;
use Commerz\Shipping\Zones\Data\ArchiveZone;
use Commerz\Shipping\Zones\Data\CreateZone;
use Commerz\Shipping\Zones\Data\ListZones;
use Commerz\Shipping\Zones\Data\RestoreZone;
use Commerz\Shipping\Zones\Data\SetZoneMembers;
use Commerz\Shipping\Zones\Data\UpdateZone;
use Commerz\Shipping\Zones\Data\ZoneId;
use Commerz\Shipping\Zones\Data\ZoneView;
use Commerz\Shipping\Zones\Exceptions\ZoneArchived;
use Commerz\Shipping\Zones\Exceptions\ZoneNotFound;

/** Delivery zones: sets of countries, regions and postal prefixes. Zones may overlap; the most specific match wins. */
#[Api(since: '0.1')]
interface Zones
{
    /**
     * @throws DuplicateCode
     * @throws InvalidCommand more members than `max_zone_members`
     */
    public function create(CreateZone $command): ZoneView;

    /** @throws ZoneNotFound|ZoneArchived */
    public function update(UpdateZone $command): ZoneView;

    /**
     * Replaces the member set.
     *
     * @throws ZoneNotFound|ZoneArchived
     * @throws InvalidCommand more members than `max_zone_members`
     */
    public function setMembers(SetZoneMembers $command): ZoneView;

    /** Archiving an archived zone changes nothing. @throws ZoneNotFound */
    public function archive(ArchiveZone $command): ZoneView;

    /** Restoring an active zone changes nothing. @throws ZoneNotFound */
    public function restore(RestoreZone $command): ZoneView;

    /** @throws ZoneNotFound */
    public function find(ZoneId $id): ZoneView;

    /** @return Page<ZoneView> */
    public function list(ListZones $query): Page;
}
