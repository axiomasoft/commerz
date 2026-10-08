<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\DeliveryRequest;

/** Offers fixed options (in the request's currency) and books with a sequential tracking number. */
final class FakeDelivery implements DeliveryOptions, ShipmentBooking
{
    /** @var list<DeliveryOption> */
    private array $offers = [];

    /** @var list<DeliveryRequest> */
    public private(set) array $requests = [];

    /** @var list<BookShipment> */
    public private(set) array $bookings = [];

    public function offer(DeliveryOption ...$options): self
    {
        $this->offers = [...$this->offers, ...array_values($options)];

        return $this;
    }

    public function options(DeliveryRequest $request): array
    {
        $this->requests[] = $request;

        return array_values(array_filter(
            $this->offers,
            static fn (DeliveryOption $option): bool => $option->price->currency->equals($request->context->currency),
        ));
    }

    public function book(BookShipment $command): BookedShipment
    {
        $this->bookings[] = $command;

        return new BookedShipment($command->carrier, 'FAKE-'.count($this->bookings));
    }
}
