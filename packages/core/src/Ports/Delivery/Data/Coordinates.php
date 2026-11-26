<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Geographic WGS-84 coordinates expressed in integer microdegrees (1° = 1,000,000 microdegrees, ~0.11 m resolution).
 */
#[Api(since: '0.1')]
final readonly class Coordinates
{
    /** Cosine lookup table for 0°..90° in 10° steps, scaled by 1,000,000. */
    private const array COS_SCALED = [
        1_000_000,
        984_808,
        939_693,
        866_025,
        766_044,
        642_788,
        500_000,
        342_020,
        173_648,
        0,
    ];

    public function __construct(
        public int $latitudeMicrodeg,
        public int $longitudeMicrodeg,
    ) {
        if ($latitudeMicrodeg < -90_000_000 || $latitudeMicrodeg > 90_000_000) {
            throw new InvalidCommand('latitudeMicrodeg', 'must be between -90000000 and 90000000', 'delivery.invalid_coordinates');
        }

        if ($longitudeMicrodeg < -180_000_000 || $longitudeMicrodeg > 180_000_000) {
            throw new InvalidCommand('longitudeMicrodeg', 'must be between -180000000 and 180000000', 'delivery.invalid_coordinates');
        }
    }

    public static function fromDecimal(string $latitude, string $longitude): self
    {
        return new self(
            self::parseMicrodegrees('latitude', $latitude),
            self::parseMicrodegrees('longitude', $longitude),
        );
    }

    public function latitudeDecimal(): string
    {
        return BigDecimal::ofUnscaledValue($this->latitudeMicrodeg, 6)->__toString();
    }

    public function longitudeDecimal(): string
    {
        return BigDecimal::ofUnscaledValue($this->longitudeMicrodeg, 6)->__toString();
    }

    /** Integer equirectangular surface distance in meters using exact BigInteger arithmetic. */
    public function distanceMetersTo(self $other): int
    {
        $dLat = abs($this->latitudeMicrodeg - $other->latitudeMicrodeg);
        $dLon = abs($this->longitudeMicrodeg - $other->longitudeMicrodeg);

        if ($dLon > 180_000_000) {
            $dLon = 360_000_000 - $dLon;
        }

        $avgLat = intdiv(abs($this->latitudeMicrodeg + $other->latitudeMicrodeg), 2);
        $bucket = min(8, intdiv($avgLat, 10_000_000));
        $remainder = $avgLat - ($bucket * 10_000_000);
        $cosScaled = self::COS_SCALED[$bucket]
            - intdiv((self::COS_SCALED[$bucket] - self::COS_SCALED[$bucket + 1]) * $remainder, 10_000_000);

        $dxMm = BigInteger::of($dLon)
            ->multipliedBy(111_195)
            ->multipliedBy($cosScaled)
            ->dividedBy(1_000_000_000, RoundingMode::HalfUp);
        $dyMm = BigInteger::of($dLat)
            ->multipliedBy(111_195)
            ->dividedBy(1_000, RoundingMode::HalfUp);

        $distMm = $dxMm->multipliedBy($dxMm)->plus($dyMm->multipliedBy($dyMm))->sqrt(RoundingMode::HalfUp);

        return $distMm->dividedBy(1_000, RoundingMode::HalfUp)->toInt();
    }

    private static function parseMicrodegrees(string $field, string $decimal): int
    {
        $trimmed = trim($decimal);

        if ($trimmed === '') {
            throw new InvalidCommand($field, 'must be a decimal coordinate string', 'delivery.invalid_coordinates');
        }

        try {
            return BigDecimal::of($trimmed)
                ->multipliedBy(1_000_000)
                ->toScale(0, RoundingMode::HalfUp)
                ->toInt();
        } catch (MathException) {
            throw new InvalidCommand($field, "'{$decimal}' is not a valid decimal coordinate", 'delivery.invalid_coordinates');
        }
    }
}
