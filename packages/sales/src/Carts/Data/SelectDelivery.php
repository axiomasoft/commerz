<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Delivery\Data\PickupPointDestination;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Packman\Attributes\Api;

/** Chooses a delivery method by its code; the quote prices it and rejects a method that cannot deliver. */
#[Api(since: '0.1')]
final readonly class SelectDelivery
{
    public function __construct(
        public CartId $cart,
        public string $methodCode,
        public DeliveryMode $mode = DeliveryMode::Shipping,
        public ?PickupPointDestination $pickupPoint = null,
    ) {
        if ($methodCode === '' || strlen($methodCode) > 64) {
            throw new InvalidCommand('methodCode', 'must be 1..64 bytes', 'sales.invalid_delivery_method');
        }

        if ($pickupPoint !== null && ! $mode->requiresPickupPoint()) {
            throw new InvalidCommand('pickupPoint', 'pickupPoint requires pickup_point or local_pickup mode', 'sales.invalid_delivery_method');
        }

        if ($pickupPoint !== null && $pickupPoint->mode !== $mode) {
            throw new InvalidCommand('pickupPoint', "pickupPoint mode '{$pickupPoint->mode->value}' does not match '{$mode->value}'", 'sales.invalid_delivery_method');
        }
    }
}
