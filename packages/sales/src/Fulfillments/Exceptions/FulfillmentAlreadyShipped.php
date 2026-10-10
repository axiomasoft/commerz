<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Ports\Delivery\Data\BookedShipment;

/** The fulfillment already left with another carrier or tracking number; a shipment is never overwritten. */
#[Api(since: '0.1')]
final class FulfillmentAlreadyShipped extends CommerzException
{
    public function __construct(
        public readonly string $fulfillment,
        public readonly ?string $carrier,
        public readonly ?string $tracking,
        public readonly ?BookedShipment $booking = null,
    ) {
        parent::__construct("Fulfillment '{$fulfillment}' is already shipped with another carrier or tracking number.");
    }

    public function code(): string
    {
        return 'sales.fulfillment_already_shipped';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        $context = ['fulfillment' => $this->fulfillment, 'carrier' => $this->carrier, 'tracking' => $this->tracking];

        if ($this->booking !== null) {
            $context['booking_reference'] = 'fulfillment:'.$this->fulfillment;
            $context['booking_carrier'] = $this->booking->carrier;
            $context['booking_tracking'] = $this->booking->tracking;
            $context['booking_label_ref'] = $this->booking->labelRef;
        }

        return $context;
    }
}
