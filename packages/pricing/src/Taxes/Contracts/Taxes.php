<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Contracts;

use Commerz\Ports\Delivery\Data\Address;
use Commerz\Pricing\Common\Exceptions\DuplicateCode;
use Commerz\Pricing\Taxes\Data\DefineTaxClass;
use Commerz\Pricing\Taxes\Data\DefineTaxZone;
use Commerz\Pricing\Taxes\Data\ListTaxRates;
use Commerz\Pricing\Taxes\Data\RemoveTaxRate;
use Commerz\Pricing\Taxes\Data\RescheduleTaxRate;
use Commerz\Pricing\Taxes\Data\SetTaxRate;
use Commerz\Pricing\Taxes\Data\SetTaxZoneMembers;
use Commerz\Pricing\Taxes\Data\TaxClassView;
use Commerz\Pricing\Taxes\Data\TaxRateView;
use Commerz\Pricing\Taxes\Data\TaxZoneView;
use Commerz\Pricing\Taxes\Exceptions\TaxClassNotFound;
use Commerz\Pricing\Taxes\Exceptions\TaxRateNotFound;
use Commerz\Pricing\Taxes\Exceptions\TaxRateOverlap;
use Commerz\Pricing\Taxes\Exceptions\TaxZoneMemberTaken;
use Commerz\Pricing\Taxes\Exceptions\TaxZoneNotFound;
use Packman\Attributes\Api;

/** Tax classes, zones with their countries and regions, and rates with validity windows. */
#[Api(since: '0.1')]
interface Taxes
{
    /** @throws DuplicateCode */
    public function defineClass(DefineTaxClass $command): TaxClassView;

    /**
     * @throws DuplicateCode
     * @throws TaxZoneMemberTaken a member belongs to another zone
     */
    public function defineZone(DefineTaxZone $command): TaxZoneView;

    /**
     * Replaces the members of the zone.
     *
     * @throws TaxZoneNotFound
     * @throws TaxZoneMemberTaken a member belongs to another zone
     */
    public function setZoneMembers(SetTaxZoneMembers $command): TaxZoneView;

    /**
     * Sets a rate; the same class, zone, tax code and window update it.
     *
     * @throws TaxClassNotFound|TaxZoneNotFound
     * @throws TaxRateOverlap another window of the same rate overlaps this one
     */
    public function setRate(SetTaxRate $command): TaxRateView;

    /**
     * @throws TaxRateNotFound
     * @throws TaxRateOverlap
     */
    public function rescheduleRate(RescheduleTaxRate $command): TaxRateView;

    /** Removing a rate that is gone changes nothing. */
    public function removeRate(RemoveTaxRate $command): void;

    /** @return list<TaxClassView> by code */
    public function listClasses(): array;

    /** @return list<TaxZoneView> by code */
    public function listZones(): array;

    /**
     * @return list<TaxRateView> by tax class, zone, tax code and start of the window
     *
     * @throws TaxClassNotFound|TaxZoneNotFound a filter names an unknown class or zone
     */
    public function listRates(ListTaxRates $query): array;

    /**
     * The zone that taxes goods sent to the address: the region's own zone, else the country's; null outside all zones.
     * What the built-in `rates` calculator uses.
     */
    public function zoneFor(Address $address): ?TaxZoneView;
}
