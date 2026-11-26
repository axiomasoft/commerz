<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Packman\Attributes\Api;

/**
 * Selected PUDO locker/point or BOPIS store destination, kept strictly separate from the buyer's billing address.
 */
#[Api(since: '0.1')]
final readonly class PickupPointDestination
{
    public function __construct(
        public string $pointId,
        public string $provider,
        public DeliveryMode $mode,
        public Address $address,
        public ?string $recipientName = null,
        public ?string $recipientPhone = null,
        public ?string $name = null,
    ) {
        if (trim($pointId) === '' || strlen($pointId) > 191) {
            throw new InvalidCommand('pointId', 'must be 1..191 bytes', 'delivery.invalid_pickup_destination');
        }

        if (trim($provider) === '' || strlen($provider) > 64) {
            throw new InvalidCommand('provider', 'must be 1..64 bytes', 'delivery.invalid_pickup_destination');
        }

        if ($mode === DeliveryMode::Shipping) {
            throw new InvalidCommand('mode', 'pickup destination mode must be pickup_point or local_pickup', 'delivery.invalid_pickup_destination');
        }
    }

    /** Resolves the physical delivery address of the pickup point with collector contact details for zone/carrier calls. */
    public function toDeliveryAddress(): Address
    {
        return new Address(
            country: $this->address->country,
            region: $this->address->region,
            city: $this->address->city,
            postalCode: $this->address->postalCode,
            lines: $this->address->lines,
            recipient: $this->recipientName ?? $this->address->recipient,
            phone: $this->recipientPhone ?? $this->address->phone,
        );
    }
}
