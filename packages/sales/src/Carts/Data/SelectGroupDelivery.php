<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\PickupPointDestination;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Packman\Attributes\Api;

/** Selects a delivery method (and optional group address or PUDO/BOPIS destination) for a cart line group. */
#[Api(since: '0.1')]
final readonly class SelectGroupDelivery
{
    public CartDeliveryGroup $group;

    /** @param  list<string>  $lineIds */
    public function __construct(
        public CartId $cart,
        public string $groupKey,
        public array $lineIds,
        public string $methodCode,
        public DeliveryMode $mode = DeliveryMode::Shipping,
        public ?Address $shippingAddress = null,
        public ?PickupPointDestination $pickupPoint = null,
    ) {
        $this->group = new CartDeliveryGroup(
            groupKey: $groupKey,
            lineIds: $lineIds,
            methodCode: $methodCode,
            mode: $mode,
            shippingAddress: $shippingAddress,
            pickupPoint: $pickupPoint,
        );
    }
}
