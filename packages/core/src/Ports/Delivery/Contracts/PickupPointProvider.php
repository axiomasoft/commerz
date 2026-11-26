<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Contracts;

use Commerz\Context\CommerceContext;
use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointQuery;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Supplies PUDO parcel lockers, carrier pickup offices, or BOPIS store locations matching city/coordinates and parcel limits.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface PickupPointProvider
{
    public const string KIND = 'pickup_point_provider';

    /** Contribution key, e.g. `database`, `cdek`, or `bopis`. */
    public function key(): string;

    /** @return list<PickupPoint> */
    public function search(PickupPointQuery $query): array;

    public function find(string $pointId, CommerceContext $context): ?PickupPoint;
}
