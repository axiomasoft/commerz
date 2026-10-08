<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\UnsupportedContribution;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;

/** Books a shipment with a carrier. May call the network, so it is always called outside a database transaction. */
#[Api(since: '0.1')]
interface ShipmentBooking
{
    /** @throws UnsupportedContribution no carrier is registered under `$command->carrier` */
    public function book(BookShipment $command): BookedShipment;
}
