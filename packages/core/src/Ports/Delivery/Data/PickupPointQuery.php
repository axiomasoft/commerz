<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Packman\Attributes\Api;

/** Search criteria for PUDO / BOPIS pickup points by city, postal code, coordinates radius, and locker dimensions/weight. */
#[Api(since: '0.1')]
final readonly class PickupPointQuery
{
    /**
     * @param  list<Parcel>  $items  optional per-item parcels to reject oversized items for small lockers
     */
    public function __construct(
        public CommerceContext $context,
        public string $country,
        public ?string $city = null,
        public ?string $postalCode = null,
        public ?Coordinates $near = null,
        public ?int $radiusMeters = null,
        public ?Parcel $parcel = null,
        public array $items = [],
        public ?DeliveryMode $mode = null,
        public ?string $provider = null,
        public bool $onlyLockers = false,
        public int $limit = 50,
    ) {
        if (preg_match('/\A[A-Z]{2}\z/', $country) !== 1) {
            throw new InvalidCommand('country', "'{$country}' is not an ISO 3166-1 alpha-2 code", 'delivery.invalid_pickup_query');
        }

        if ($radiusMeters !== null && $radiusMeters < 1) {
            throw new InvalidCommand('radiusMeters', 'must be positive', 'delivery.invalid_pickup_query');
        }

        if ($radiusMeters !== null && $near === null) {
            throw new InvalidCommand('radiusMeters', 'requires near coordinates', 'delivery.invalid_pickup_query');
        }

        if ($mode === DeliveryMode::Shipping) {
            throw new InvalidCommand('mode', 'pickup point query mode must be pickup_point or local_pickup', 'delivery.invalid_pickup_query');
        }

        if ($provider !== null && (trim($provider) === '' || strlen($provider) > 64)) {
            throw new InvalidCommand('provider', 'must be 1..64 bytes', 'delivery.invalid_pickup_query');
        }

        if ($limit < 1 || $limit > 500) {
            throw new InvalidCommand('limit', 'must be between 1 and 500', 'delivery.invalid_pickup_query');
        }
    }
}
