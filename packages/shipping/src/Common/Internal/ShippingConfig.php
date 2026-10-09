<?php

declare(strict_types=1);

namespace Commerz\Shipping\Common\Internal;

use Commerz\Errors\InvalidConfiguration;

/** The only reader of `config('commerz-shipping.*')`. */
final readonly class ShippingConfig
{
    public function __construct(
        public bool $taxIncluded,
        public int $maxZoneMembers,
        public int $bookingLeaseSeconds,
    ) {}

    /** @param  array<array-key, mixed>  $config */
    public static function fromArray(array $config, string $path = 'commerz-shipping'): self
    {
        $taxIncluded = $config['tax_included'] ?? false;

        if (! is_bool($taxIncluded)) {
            throw new InvalidConfiguration("{$path}.tax_included", 'expected true or false');
        }

        $booking = $config['booking'] ?? [];

        if (! is_array($booking)) {
            throw new InvalidConfiguration("{$path}.booking", 'expected an array');
        }

        return new self(
            $taxIncluded,
            self::int($config['max_zone_members'] ?? 2000, 1, 100_000, "{$path}.max_zone_members"),
            self::int($booking['lease_seconds'] ?? 120, 10, 3600, "{$path}.booking.lease_seconds"),
        );
    }

    private static function int(mixed $value, int $min, int $max, string $path): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidConfiguration($path, "expected an integer from {$min} to {$max}");
        }

        return $value;
    }
}
