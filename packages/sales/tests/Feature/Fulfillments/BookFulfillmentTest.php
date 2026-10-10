<?php

declare(strict_types=1);

use Commerz\Errors\UnsupportedContribution;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Fulfillments\Contracts\Fulfillments;
use Commerz\Sales\Fulfillments\Data\BookFulfillment;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Fulfillments\Data\FulfillmentId;
use Commerz\Sales\Fulfillments\Data\ShipFulfillment;
use Commerz\Sales\Fulfillments\Enums\FulfillmentStatus;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentAlreadyShipped;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotBookable;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotFound;
use Commerz\Sales\Sales;
use Commerz\Testkit\Commerce;

require_once __DIR__.'/Helpers.php';

uses(SlsEnvironment::class);

// D14/D29/D30: the carrier is booked outside any transaction, by the reference `fulfillment:{id}`, and the fulfillment
// ships with what the carrier answered. The booking refuses to run in a transaction, so the data is committed and
// emptied after each test.

beforeEach(function (): void {
    slsCommitted();
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(
        slsSnapshot('fake:a', '100.00'),
        slsSnapshot('fake:b', '30.00'),
        slsSnapshot('fake:svc', '50.00', SellableKind::Service),
    );
    $cart = slsCartWith(slsChannel(), ['fake:a' => 3, 'fake:b' => 2, 'fake:svc' => 1]);
    slsCarts()->selectDelivery(new SelectDelivery($cart->id, 'flat:300.00'));
    $this->order = slsPlace($cart);
});

afterEach(function (): void {
    slsTruncate();
});

/** Replaces the carrier port, as a host with a failing carrier would, for the modules resolved from now on. */
function bkfCarrier(ShipmentBooking $carrier): void
{
    app()->instance(ShipmentBooking::class, $carrier);
    app()->forgetInstance(Fulfillments::class);
    app()->forgetInstance(Sales::class);
}

it('books the shipment of the fulfilled lines with the carrier and address the order was placed with', function (): void {
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [
        new LineQuantity(slsLine($this->order, 'fake:a'), 2),
        new LineQuantity(slsLine($this->order, 'fake:b'), 1),
        new LineQuantity(slsLine($this->order, 'fake:svc'), 1),
    ]));

    $booked = slsFulfillments()->book(new BookFulfillment($created->id, 300, 200, 100, ['label' => 'A6']));
    $request = $this->commerce->delivery->bookings[0];

    expect($this->commerce->delivery->bookings)->toHaveCount(1)
        ->and($request)->toBeInstanceOf(BookShipment::class)
        ->and([$request->reference, $request->carrier, $request->methodCode])->toBe(['fulfillment:'.$created->id->value, 'test', 'flat:300.00'])
        ->and($request->destination)->toEqual(slsAddress())
        ->and($request->meta)->toBe(['label' => 'A6'])
        // 500 g per unit of a and of b; the service weighs and is declared nothing: 2/3 of 300.00 and 1/2 of 60.00.
        ->and($request->parcel)->toEqual(new Parcel(1500, Money::of('230.00', 'RUB'), 300, 200, 100))
        ->and($booked->status)->toBe(FulfillmentStatus::Shipped)
        ->and([$booked->carrier, $booked->tracking, $booked->methodCode, $booked->labelRef])->toBe(['test', 'FAKE-1', 'flat:300.00', null])
        ->and(fulOutbox('sales.fulfillment_shipped'))->toBe(1)
        ->and(fulHistory($this->order))->toContain('fulfillment_shipped');
});

it('keeps the label of the carrier', function (): void {
    bkfCarrier(new class implements ShipmentBooking
    {
        public function book(BookShipment $command): BookedShipment
        {
            return new BookedShipment('cdek', 'TR-9', 'label:77');
        }
    });
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));

    $booked = slsFulfillments()->book(new BookFulfillment($created->id));

    expect([$booked->carrier, $booked->tracking, $booked->labelRef, $booked->methodCode])->toBe(['cdek', 'TR-9', 'label:77', 'flat:300.00']);
});

it('declares the exact fulfilled share of a line total', function (): void {
    $this->commerce->sellables->add(slsSnapshot('fake:c', '10.00'));
    $cart = slsCartWith(slsChannel('c', 'C-'), ['fake:c' => 3]);
    slsCarts()->selectDelivery(new SelectDelivery($cart->id, 'flat:0.00'));
    $order = slsPlace($cart);
    $created = slsFulfillments()->create(new CreateFulfillment($order->id, [new LineQuantity(slsLine($order, 'fake:c'), 1)]));

    slsFulfillments()->book(new BookFulfillment($created->id));

    // 3 units cost 30.00 and the third of it is exact; weight is 500 g a unit.
    expect($this->commerce->delivery->bookings[0]->parcel)->toEqual(new Parcel(500, Money::of('10.00', 'RUB')));
});

it('refuses to run inside a transaction and calls nobody', function (): void {
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));

    expect(fn () => app(Transactions::class)->run(static fn () => slsFulfillments()->book(new BookFulfillment($created->id))))
        ->toThrow(OperationInsideTransaction::class)
        ->and($this->commerce->delivery->bookings)->toBe([])
        ->and(slsFulfillments()->find($created->id)->status)->toBe(FulfillmentStatus::Created);
});

it('has nothing to book for an order placed without a delivery method', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $plain = slsPlace(slsCartWith(slsChannel('p', 'P-'), ['fake:a' => 1]));
    $created = slsFulfillments()->create(new CreateFulfillment($plain->id, [new LineQuantity(slsLine($plain, 'fake:a'), 1)]));

    expect(fn () => slsFulfillments()->book(new BookFulfillment($created->id)))
        ->toThrow(FulfillmentNotBookable::class, 'no_delivery')
        ->and($this->commerce->delivery->bookings)->toBe([]);
});

it('has nothing to ship when no fulfilled line needs shipping', function (): void {
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:svc'), 1)]));

    expect(fn () => slsFulfillments()->book(new BookFulfillment($created->id)))
        ->toThrow(FulfillmentNotBookable::class, 'nothing_to_ship')
        ->and($this->commerce->delivery->bookings)->toBe([]);
});

it('returns a shipped fulfillment without calling the carrier again', function (): void {
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));
    $booked = slsFulfillments()->book(new BookFulfillment($created->id));

    expect(slsFulfillments()->book(new BookFulfillment($created->id)))->toEqual($booked)
        ->and($this->commerce->delivery->bookings)->toHaveCount(1)
        ->and(fulOutbox('sales.fulfillment_shipped'))->toBe(1);

    $manual = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:b'), 1)]));
    slsFulfillments()->ship(new ShipFulfillment($manual->id, 'manual', 'RB1'));

    expect(slsFulfillments()->book(new BookFulfillment($manual->id))->tracking)->toBe('RB1')
        ->and($this->commerce->delivery->bookings)->toHaveCount(1);
});

it('leaves the fulfillment created when the carrier fails, so that a retry books it', function (): void {
    $calls = [];
    bkfCarrier(new class($calls) implements ShipmentBooking
    {
        /** @param  list<string>  $calls */
        public function __construct(private array &$calls) {}

        public function book(BookShipment $command): BookedShipment
        {
            $this->calls[] = $command->reference;

            if (count($this->calls) === 1) {
                throw new RuntimeException('carrier timed out');
            }

            return new BookedShipment($command->carrier, 'TR-1');
        }
    });
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));

    expect(fn () => slsFulfillments()->book(new BookFulfillment($created->id)))->toThrow(RuntimeException::class, 'timed out')
        ->and(slsFulfillments()->find($created->id)->status)->toBe(FulfillmentStatus::Created)
        ->and(fulOutbox('sales.fulfillment_shipped'))->toBe(0);

    $booked = slsFulfillments()->book(new BookFulfillment($created->id));

    // The same reference both times: the carrier side deduplicates by it.
    expect($booked->tracking)->toBe('TR-1')
        ->and($calls)->toBe(['fulfillment:'.$created->id->value, 'fulfillment:'.$created->id->value]);
});

it('passes on an unsupported carrier and keeps the fulfillment created', function (): void {
    bkfCarrier(new class implements ShipmentBooking
    {
        public function book(BookShipment $command): BookedShipment
        {
            throw new UnsupportedContribution('carrier', $command->carrier, 'not installed');
        }
    });
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));

    expect(fn () => slsFulfillments()->book(new BookFulfillment($created->id)))->toThrow(UnsupportedContribution::class)
        ->and(slsFulfillments()->find($created->id)->status)->toBe(FulfillmentStatus::Created);
});

it('refuses to overwrite a shipment made by hand while the carrier was being asked', function (): void {
    $created = slsFulfillments()->create(new CreateFulfillment($this->order->id, [new LineQuantity(slsLine($this->order, 'fake:a'), 1)]));
    bkfCarrier(new readonly class($created->id) implements ShipmentBooking
    {
        public function __construct(private FulfillmentId $fulfillment) {}

        public function book(BookShipment $command): BookedShipment
        {
            // Another operator ships it by hand during the call.
            slsFulfillments()->ship(new ShipFulfillment($this->fulfillment, 'manual', 'HAND-1'));

            return new BookedShipment($command->carrier, 'TR-2');
        }
    });

    expect(fn () => slsFulfillments()->book(new BookFulfillment($created->id)))->toThrow(FulfillmentAlreadyShipped::class)
        ->and(slsFulfillments()->find($created->id)->tracking)->toBe('HAND-1');
});

it('does not know a fulfillment that does not exist', function (): void {
    expect(fn () => slsFulfillments()->book(new BookFulfillment(FulfillmentId::generate())))->toThrow(FulfillmentNotFound::class);
});
