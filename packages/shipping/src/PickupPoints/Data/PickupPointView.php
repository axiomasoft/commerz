<?php

declare(strict_types=1);

namespace Commerz\Shipping\PickupPoints\Data;

use Commerz\Measures\LocalizedText;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\Coordinates;
use Commerz\Ports\Delivery\Data\PickupPoint;
use Commerz\Ports\Delivery\Data\PickupPointLimits;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Shipping\PickupPoints\Enums\PickupPointStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PickupPointView
{
    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public PickupPointId $id,
        public string $provider,
        public string $code,
        public LocalizedText $name,
        public DeliveryMode $mode,
        public Address $address,
        public ?Coordinates $coordinates,
        public ?PickupPointLimits $limits,
        public bool $isLocker,
        public ?string $openingHours,
        public PickupPointStatus $status,
        public array $meta,
        public DateTimeImmutable $updatedAt,
    ) {}

    public function toPickupPoint(string $locale): PickupPoint
    {
        return new PickupPoint(
            id: $this->code,
            provider: $this->provider,
            name: $this->name->resolve($locale),
            mode: $this->mode,
            address: $this->address,
            coordinates: $this->coordinates,
            limits: $this->limits,
            isLocker: $this->isLocker,
            openingHours: $this->openingHours,
            meta: $this->meta,
        );
    }
}
