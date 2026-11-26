<?php

declare(strict_types=1);

namespace Commerz\Shipping\PickupPoints\Contracts;

use Commerz\Context\CommerceContext;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointDestination;
use Commerz\Ports\Delivery\Data\PickupPointQuery;
use Commerz\Shipping\PickupPoints\Data\PickupPointView;
use Commerz\Shipping\PickupPoints\Data\UpsertPickupPoint;
use Commerz\Shipping\PickupPoints\Exceptions\PickupPointNotFound;
use Commerz\Shipping\PickupPoints\Exceptions\PickupPointOversized;
use Packman\Attributes\Api;

/**
 * Manages stored PUDO/BOPIS locations and searches across all `PickupPointProvider` contributions with locker
 * dimension and weight filtering.
 */
#[Api(since: '0.1')]
interface PickupPoints
{
    public function upsert(UpsertPickupPoint $command): PickupPointView;

    /** @throws PickupPointNotFound */
    public function enable(string $provider, string $code): PickupPointView;

    /** @throws PickupPointNotFound */
    public function disable(string $provider, string $code): PickupPointView;

    /** @throws PickupPointNotFound */
    public function archive(string $provider, string $code): PickupPointView;

    /** @throws PickupPointNotFound */
    public function findView(string $provider, string $code): PickupPointView;

    /** @throws PickupPointNotFound */
    public function find(string $pointId, CommerceContext $context, ?string $provider = null): PickupPoint;

    /**
     * Searches active pickup points across providers, rejecting small lockers that cannot fit the parcel or items.
     *
     * @return list<PickupPoint>
     */
    public function search(PickupPointQuery $query): array;

    /**
     * Verifies that a selected pickup destination exists and its locker/counter limits accept the parcel and items.
     *
     * @param  list<Parcel>  $items
     *
     * @throws PickupPointNotFound
     * @throws PickupPointOversized
     */
    public function validateSelection(
        PickupPointDestination $destination,
        Parcel $parcel,
        CommerceContext $context,
        array $items = [],
    ): PickupPoint;
}
