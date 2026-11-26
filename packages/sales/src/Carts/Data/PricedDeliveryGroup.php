<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\Parcel;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PricedDeliveryGroup
{
    public function __construct(
        public CartDeliveryGroup $group,
        public DeliveryOption $option,
        public Parcel $parcel,
        public Address $destination,
    ) {}
}
