<?php

declare(strict_types=1);

namespace Commerz\Shipping\PickupPoints\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\Coordinates;
use Commerz\Ports\Delivery\Data\PickupPointLimits;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Shipping\Common\Data\ShippingCodes;
use Commerz\Shipping\PickupPoints\Enums\PickupPointStatus;
use Packman\Attributes\Api;

/** Creates or updates a stored PUDO locker/point or BOPIS local pickup location. */
#[Api(since: '0.1')]
final readonly class UpsertPickupPoint
{
    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public string $provider,
        public string $code,
        public LocalizedText $name,
        public Address $address,
        public DeliveryMode $mode = DeliveryMode::PickupPoint,
        public ?Coordinates $coordinates = null,
        public ?PickupPointLimits $limits = null,
        public bool $isLocker = false,
        public ?string $openingHours = null,
        public PickupPointStatus $status = PickupPointStatus::Active,
        public array $meta = [],
    ) {
        ShippingCodes::carrierKey('provider', $provider);

        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,190}\z/', $code) !== 1) {
            throw new InvalidCommand('code', 'must match [a-zA-Z0-9][a-zA-Z0-9_.:-]{0,190}', 'shipping.invalid_pickup_point');
        }

        foreach ($name->texts as $text) {
            if (mb_strlen($text) > 191) {
                throw new InvalidCommand('name', 'each localized name must be at most 191 characters', 'shipping.invalid_pickup_point');
            }
        }

        if ($address->city === null || trim($address->city) === '') {
            throw new InvalidCommand('address.city', 'pickup point address must include a city', 'shipping.invalid_pickup_point');
        }

        if ($mode === DeliveryMode::Shipping) {
            throw new InvalidCommand('mode', 'pickup point mode must be pickup_point or local_pickup', 'shipping.invalid_pickup_point');
        }

        if ($status === PickupPointStatus::Archived) {
            throw new InvalidCommand('status', 'cannot upsert directly as archived', 'shipping.invalid_pickup_point');
        }
    }
}
