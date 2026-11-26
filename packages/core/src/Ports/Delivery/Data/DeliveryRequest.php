<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class DeliveryRequest
{
    /** @param  list<SellableRef>  $sellables  what is shipped, for methods limited to some goods */
    public function __construct(
        public CommerceContext $context,
        public Address $destination,
        public Parcel $parcel,
        public array $sellables = [],
        public ?PickupPointDestination $pickupPoint = null,
        public ?DeliveryMode $mode = null,
        public FulfillmentConstraints $constraints = new FulfillmentConstraints,
    ) {}
}
