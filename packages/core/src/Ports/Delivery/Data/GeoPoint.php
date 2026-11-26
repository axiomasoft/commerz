<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Geographic coordinates stored as exact integer micro-degrees (degrees * 1_000_000, ~0.11 m resolution)
 * to avoid floating-point values on the public data surface.
 */
#[Api(since: '0.1')]
final readonly class GeoPoint
{
    public const int SCALE = 1_000_000;

    private const int MIN_LATITUDE = -90_000_000;

    private const int MAX_LATITUDE = 90_000_000;

    private const int MIN_LONGITUDE = -180_000_000;

    private const int MAX_LONGITUDE = 180_000_000;

    private const int EARTH_RADIUS_METERS = 6_371_000;

    public function __construct(
        public int $latitudeMicro,
        public int $longitudeMicro,
    ) {
        if ($latitudeMicro < self::MIN_LATITUDE || $latitudeMicro > self::MAX_LATITUDE) {
            throw new InvalidCommand('latitudeMicro', 'must be between -90000000 and 90000000', 'delivery.invalid_coordinates');
        }

        if ($longitudeMicro < self::MIN_LONGITUDE || $longitudeMicro > self::MAX_LONGITUDE) {
            throw new InvalidCommand('longitudeMicro', 'must be between -180000000 and 180000000', 'delivery.invalid_coordinates');
        }
    }

    /** Great-circle Haversine distance to `$other` in whole meters. */
    public function distanceMetersTo(self $other): int
    {
        if ($this->latitudeMicro === $other->latitudeMicro && $this->longitudeMicro === $other->longitudeMicro) {
            return 0;
        }

        $lat1 = deg2rad($this->latitudeMicro / self::SCALE);
        $lat2 = deg2rad($other->latitudeMicro / self::SCALE);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad(($other->longitudeMicro - $this->longitudeMicro) / self::SCALE);

        $sinLat = sin($dLat / 2);
        $sinLon = sin($dLon / 2);
        $h = ($sinLat * $sinLat) + (cos($lat1) * cos($lat2) * $sinLon * $sinLon);
        $c = 2 * atan2(sqrt($h), sqrt(max(0.0, 1.0 - $h)));

        return (int) round(self::EARTH_RADIUS_METERS * $c);
    }
}
