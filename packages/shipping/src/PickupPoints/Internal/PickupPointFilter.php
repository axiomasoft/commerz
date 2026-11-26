<?php

declare(strict_types=1);

namespace Commerz\Shipping\PickupPoints\Internal;

use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointQuery;
use Commerz\Shipping\Common\Data\PostalCode;
use Packman\Attributes\Internal;

/**
 * Applies uniform PUDO / BOPIS filtering across any `PickupPointProvider`: country, city, postal prefix, coordinates
 * radius, mode, locker flag, and 3D locker compartment / weight limits.
 */
#[Internal]
final class PickupPointFilter
{
    /**
     * @param  list<PickupPoint>  $points
     * @return list<PickupPoint>
     */
    public static function apply(array $points, PickupPointQuery $query): array
    {
        $normalizedCity = $query->city === null || trim($query->city) === '' ? null : mb_strtolower(trim($query->city), 'UTF-8');
        $normalizedPostal = PostalCode::normalize($query->postalCode);
        $matched = [];

        foreach ($points as $point) {
            if ($point->address->country !== $query->country) {
                continue;
            }

            if ($query->provider !== null && $point->provider !== $query->provider) {
                continue;
            }

            if ($query->mode !== null && $point->mode !== $query->mode) {
                continue;
            }

            if ($query->onlyLockers && ! $point->isLocker) {
                continue;
            }

            if ($normalizedCity !== null) {
                $pointCity = $point->address->city === null ? '' : mb_strtolower(trim($point->address->city), 'UTF-8');

                if ($pointCity !== $normalizedCity) {
                    continue;
                }
            }

            if ($normalizedPostal !== null) {
                $pointPostal = PostalCode::normalize($point->address->postalCode);

                if ($pointPostal === null || ! str_starts_with($pointPostal, $normalizedPostal)) {
                    continue;
                }
            }

            $distance = null;

            if ($query->near !== null) {
                if ($point->coordinates === null) {
                    if ($query->radiusMeters !== null) {
                        continue;
                    }
                } else {
                    $distance = $query->near->distanceMetersTo($point->coordinates);

                    if ($query->radiusMeters !== null && $distance > $query->radiusMeters) {
                        continue;
                    }
                }
            }

            if ($query->parcel !== null && ! $point->accepts($query->parcel, $query->items)) {
                continue;
            }

            if ($query->parcel === null && $query->items !== []) {
                $allFit = true;

                foreach ($query->items as $item) {
                    if ($point->limits !== null && ! $point->limits->fitsParcel($item)) {
                        $allFit = false;

                        break;
                    }
                }

                if (! $allFit) {
                    continue;
                }
            }

            $matched[] = ['point' => $point, 'distance' => $distance];
        }

        usort($matched, static function (array $a, array $b): int {
            if ($a['distance'] !== null && $b['distance'] !== null && $a['distance'] !== $b['distance']) {
                return $a['distance'] <=> $b['distance'];
            }

            if ($a['distance'] !== null && $b['distance'] === null) {
                return -1;
            }

            if ($a['distance'] === null && $b['distance'] !== null) {
                return 1;
            }

            $byProvider = strcmp($a['point']->provider, $b['point']->provider);

            return $byProvider !== 0 ? $byProvider : strcmp($a['point']->id, $b['point']->id);
        });

        return array_slice(
            array_map(static fn (array $entry): PickupPoint => $entry['point'], $matched),
            0,
            $query->limit,
        );
    }
}
