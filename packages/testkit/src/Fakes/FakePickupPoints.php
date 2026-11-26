<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Context\CommerceContext;
use Commerz\Ports\Delivery\Contracts\PickupPointProvider;
use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointQuery;
use Override;
use Packman\Attributes\Api;

/**
 * In-memory `PickupPointProvider` supporting country, city, postal prefix, radius, mode, locker, and parcel limit filtering.
 */
#[Api(since: '0.1')]
final class FakePickupPoints implements PickupPointProvider
{
    /** @var array<string, PickupPoint> */
    private array $points = [];

    /** @var list<PickupPointQuery> */
    public private(set) array $queries = [];

    /** @var list<string> */
    public private(set) array $lookups = [];

    public function __construct(private readonly string $key = 'fake') {}

    public function add(PickupPoint ...$points): self
    {
        foreach ($points as $point) {
            $this->points[$point->id] = $point;
        }

        return $this;
    }

    #[Override]
    public function key(): string
    {
        return $this->key;
    }

    /** @return list<PickupPoint> */
    #[Override]
    public function search(PickupPointQuery $query): array
    {
        $this->queries[] = $query;

        $normalizedCity = $query->city === null || trim($query->city) === ''
            ? null
            : mb_strtolower(trim($query->city), 'UTF-8');
        $normalizedPostal = self::normalizePostal($query->postalCode);
        $matched = [];

        foreach ($this->points as $point) {
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
                $pointPostal = self::normalizePostal($point->address->postalCode);

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

    #[Override]
    public function find(string $pointId, CommerceContext $context): ?PickupPoint
    {
        $this->lookups[] = $pointId;

        return $this->points[$pointId] ?? null;
    }

    private static function normalizePostal(?string $postalCode): ?string
    {
        if ($postalCode === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[\s-]+/', '', trim($postalCode)) ?? '');

        return $normalized === '' ? null : $normalized;
    }
}
