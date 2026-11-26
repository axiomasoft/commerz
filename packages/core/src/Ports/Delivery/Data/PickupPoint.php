<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Packman\Attributes\Api;

/** A carrier PUDO point (parcel locker / staffed counter) or merchant BOPIS store pickup location. */
#[Api(since: '0.1')]
final readonly class PickupPoint
{
    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public string $id,
        public string $provider,
        public string $name,
        public DeliveryMode $mode,
        public Address $address,
        public ?Coordinates $coordinates = null,
        public ?PickupPointLimits $limits = null,
        public bool $isLocker = false,
        public ?string $openingHours = null,
        public array $meta = [],
    ) {
        if (trim($id) === '' || strlen($id) > 191) {
            throw new InvalidCommand('id', 'must be 1..191 bytes', 'delivery.invalid_pickup_point');
        }

        if (trim($provider) === '' || strlen($provider) > 64) {
            throw new InvalidCommand('provider', 'must be 1..64 bytes', 'delivery.invalid_pickup_point');
        }

        if (trim($name) === '') {
            throw new InvalidCommand('name', 'must not be blank', 'delivery.invalid_pickup_point');
        }

        if ($mode === DeliveryMode::Shipping) {
            throw new InvalidCommand('mode', 'pickup point mode must be pickup_point or local_pickup', 'delivery.invalid_pickup_point');
        }
    }

    /**
     * @param  list<Parcel>  $items
     */
    public function accepts(Parcel $parcel, array $items = [], bool $allowRotation = true): bool
    {
        return $this->limits === null || $this->limits->fitsAll($parcel, $items, $allowRotation);
    }

    public function toDestination(?string $recipientName = null, ?string $recipientPhone = null): PickupPointDestination
    {
        return new PickupPointDestination(
            pointId: $this->id,
            provider: $this->provider,
            mode: $this->mode,
            address: $this->address,
            recipientName: $recipientName,
            recipientPhone: $recipientPhone,
            name: $this->name,
        );
    }
}
