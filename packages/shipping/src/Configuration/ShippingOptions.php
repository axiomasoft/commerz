<?php

declare(strict_types=1);

namespace Commerz\Shipping\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;

/**
 * Immutable compiled configuration snapshot for `axiomasoft/commerz-shipping`.
 */
#[Api(since: '0.1')]
final readonly class ShippingOptions implements OptionsFactory
{
    /** @var list<string> */
    private const array TOP_KEYS = [
        'tax_included',
        'max_zone_members',
        'booking',
    ];

    /** @var list<string> */
    private const array BOOKING_KEYS = [
        'lease_seconds',
    ];

    public function __construct(
        public bool $taxIncluded = false,
        public int $maxZoneMembers = 2000,
        public int $bookingLeaseSeconds = 120,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'commerz-shipping'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, self::TOP_KEYS, $path);

        $taxIncluded = $config['tax_included'] ?? false;

        if (! is_bool($taxIncluded)) {
            $validator->add($path.'.tax_included', 'expected true or false');
            $taxIncluded = false;
        }

        $maxZoneMembers = self::int($validator, $config['max_zone_members'] ?? 2000, 1, 100_000, $path.'.max_zone_members');

        $booking = $config['booking'] ?? [];

        if (! is_array($booking)) {
            $validator->add($path.'.booking', 'expected an array');
            $booking = [];
        } else {
            $validator->rejectUnknownKeys($booking, self::BOOKING_KEYS, $path.'.booking');
        }

        $bookingLeaseSeconds = self::int($validator, $booking['lease_seconds'] ?? 120, 10, 3600, $path.'.booking.lease_seconds');

        $validator->failIfAny();

        return new self(
            $taxIncluded,
            $maxZoneMembers,
            $bookingLeaseSeconds,
        );
    }

    private static function int(ConfigurationValidator $validator, mixed $value, int $min, int $max, string $path): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            $validator->add($path, "expected an integer from {$min} to {$max}");

            return $min;
        }

        return $value;
    }
}
