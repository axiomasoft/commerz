<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Internal;

use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Packman\Attributes\Internal;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

/** Default without the shipping package: nothing is offered and no carrier can book. */
#[Internal]
final readonly class NoDelivery implements DeliveryOptions, ShipmentBooking
{
    public function options(DeliveryRequest $request): array
    {
        return [];
    }

    public function book(BookShipment $command): BookedShipment
    {
        throw new UnsupportedContribution('carrier', $command->carrier, 'no installed package implements ShipmentBooking');
    }
}
