<?php

declare(strict_types=1);

// The booking protocol of knowledge/shipping-design#booking (SHP-03, SHP-04, D29), one process at a time.

use Commerz\Errors\IdempotencyConflict;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Shipping\Bookings\Enums\BookingState;
use Commerz\Shipping\Bookings\Events\ShipmentBooked;
use Commerz\Shipping\Bookings\Exceptions\BookingOutcomeUnknown;
use Commerz\Shipping\Bookings\Internal\BookingJournal;
use Commerz\Shipping\Bookings\Internal\CarrierShipmentBooking;
use Commerz\Shipping\Methods\Data\ArchiveMethod;
use Commerz\Shipping\Methods\Data\DisableMethod;
use Commerz\Shipping\Tests\Fixtures\RecordingCarrier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Helpers.php';

uses(BkEnvironment::class);

beforeEach(fn () => bkSetUp());

afterEach(fn () => bkTruncate());

it('binds the port to the journaled booking', function (): void {
    expect(app(ShipmentBooking::class))->toBeInstanceOf(CarrierShipmentBooking::class);
});

it('books a manual shipment under the tracking number of the meta, else the reference', function (): void {
    $withTracking = bkBook(bkCommand('fulfillment:1', ['tracking' => 'MAN-7'], method: 'courier', carrier: 'manual'));
    $plain = bkBook(bkCommand('fulfillment:2', method: 'courier', carrier: 'manual'));
    $row = bkRow('fulfillment:1');

    expect([$withTracking->carrier, $withTracking->tracking, $plain->tracking])->toBe(['manual', 'MAN-7', 'fulfillment:2'])
        ->and([$row->state, $row->attempts, $row->tracking, $row->method_code, $row->booked_at !== null])
        ->toBe(['booked', 1, 'MAN-7', 'courier', true]);
});

it('records the shipment the carrier booked', function (): void {
    $booked = bkBook(bkCommand());
    $row = bkRow();

    expect([$booked->tracking, $booked->labelRef, $booked->meta])->toBe(['TRK-fulfillment:1', 'label:fulfillment:1', ['attempt' => 1]])
        ->and([$row->state, $row->tracking, $row->label_ref, json_decode($row->result, true), $row->error_code])
        ->toBe(['booked', 'TRK-fulfillment:1', 'label:fulfillment:1', ['attempt' => 1], null])
        ->and(RecordingCarrier::calls())->toBe(['book fulfillment:1 1']);
});

it('returns the recorded shipment for a repeat with the same payload without calling the carrier', function (): void {
    $first = bkBook(bkCommand());
    $again = bkBook(bkCommand());

    expect($again)->toEqual($first)
        ->and(RecordingCarrier::calls())->toBe(['book fulfillment:1 1'])
        ->and(bkRow()->attempts)->toBe(1);
});

it('refuses a booked reference with another payload', function (): void {
    bkBook(bkCommand());

    expect(fn () => bkBook(bkCommand(city: 'Tula')))->toThrow(IdempotencyConflict::class)
        ->and(mtFailure(fn () => bkBook(bkCommand(meta: ['note' => 'x']))))->toBe('common.idempotency_conflict')
        ->and(RecordingCarrier::calls())->toHaveCount(1);
});

it('refuses to book inside a transaction and writes nothing (SHP-04)', function (): void {
    $failure = mtFailure(fn () => app(Transactions::class)->run(fn () => bkBook(bkCommand())));

    expect($failure)->toBe('shipping.booking_inside_transaction')
        ->and(DB::table('cmz_shipping_bookings')->count())->toBe(0)
        ->and(RecordingCarrier::calls())->toBe([]);
});

it('checks the method and its carrier before it journals anything', function (): void {
    mtMethods()->disable(new DisableMethod(mtMethods()->findByCode('recorded')->id));
    mtMethod('gone', ['carrier' => RecordingCarrier::KEY]);
    mtMethods()->archive(new ArchiveMethod(mtMethods()->findByCode('gone')->id));
    mtMethod('lost', ['carrier' => RecordingCarrier::KEY]);
    DB::table(mtTable('shipping_methods'))->where('code', 'lost')->update(['carrier' => 'uninstalled']);

    expect(mtFailure(fn () => bkBook(bkCommand('f:1', method: 'missing'))))->toBe('shipping.method_not_found')
        ->and(mtFailure(fn () => bkBook(bkCommand('f:2', method: 'gone'))))->toBe('shipping.method_archived')
        ->and(mtFailure(fn () => bkBook(bkCommand('f:3', method: 'courier'))))->toBe('shipping.carrier_mismatch @ carrier')
        ->and(mtFailure(fn () => bkBook(bkCommand('f:4', method: 'lost', carrier: 'uninstalled'))))->toBe('plugins.unsupported_contribution')
        ->and(DB::table('cmz_shipping_bookings')->pluck('reference')->all())->toBe([])
        // A disabled method is no longer offered, yet an order placed with it is still shipped.
        ->and(bkOutcome(bkCommand('f:5')))->toBe('booked TRK-f:5');
});

it('records a rejection and lets a corrected payload book again', function (): void {
    expect(mtFailure(fn () => bkBook(bkCommand(meta: ['fail' => 'reject']))))->toBe('shipping.carrier_rejected');

    $rejected = bkRow();

    expect([$rejected->state, $rejected->error_code, $rejected->error_message, $rejected->tracking])
        ->toBe(['rejected', 'address_unreachable', 'The carrier does not serve the address.', null])
        ->and(bkOutcome(bkCommand(city: 'Tula')))->toBe('booked TRK-fulfillment:1');

    $booked = bkRow();

    expect([$booked->state, $booked->attempts, $booked->error_code])->toBe(['booked', 2, null])
        ->and(RecordingCarrier::calls())->toBe(['book fulfillment:1 1', 'book fulfillment:1 2']);
});

it('turns an error of the carrier into an unknown outcome', function (): void {
    try {
        bkBook(bkCommand(meta: ['fail' => 'timeout']));
        $this->fail('The booking did not fail.');
    } catch (BookingOutcomeUnknown $e) {
        expect($e->code())->toBe('shipping.booking_outcome_unknown')
            ->and($e->category()->value)->toBe('integration')
            ->and($e->context())->toBe(['reference' => 'fulfillment:1', 'carrier' => RecordingCarrier::KEY])
            ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }

    $row = bkRow();

    expect([$row->state, $row->error_code, $row->error_message])->toBe(['unknown', RuntimeException::class, 'The carrier timed out.']);
});

it('keeps a booking unknown when the carrier answers with an invalid result', function (): void {
    expect(mtFailure(fn () => bkBook(bkCommand(meta: ['fail' => 'invalid']))))->toBe('shipping.invalid_carrier_result');

    expect([bkRow()->state, bkRow()->error_code])->toBe(['unknown', 'shipping.invalid_carrier_result']);
});

it('asks the carrier before booking again after an unknown outcome (SHP-03)', function (): void {
    $command = bkCommand(meta: ['fail' => 'timeout', 'fail_attempts' => 1]);

    expect(bkOutcome($command))->toBe('shipping.booking_outcome_unknown')
        ->and(bkOutcome($command))->toBe('booked TRK-fulfillment:1')
        ->and(RecordingCarrier::calls())->toBe(['book fulfillment:1 1', 'find fulfillment:1 2', 'book fulfillment:1 2'])
        ->and([bkRow()->state, bkRow()->attempts])->toBe(['booked', 2]);
});

it('records the shipment the carrier finds instead of booking it twice', function (): void {
    $command = bkCommand(meta: ['fail' => 'timeout', 'found' => true]);
    bkOutcome($command);

    expect(bkOutcome($command))->toBe('booked TRK-fulfillment:1')
        ->and(RecordingCarrier::calls())->toBe(['book fulfillment:1 1', 'find fulfillment:1 2'])
        ->and(json_decode(bkRow()->result, true))->toBe(['attempt' => 2]);
});

it('stays unknown and books nothing while the carrier cannot be asked', function (): void {
    $command = bkCommand(meta: ['fail' => 'timeout', 'find_fail' => true]);
    bkOutcome($command);

    expect(bkOutcome($command))->toBe('shipping.booking_outcome_unknown')
        ->and(RecordingCarrier::calls())->toBe(['book fulfillment:1 1', 'find fulfillment:1 2'])
        ->and([bkRow()->state, bkRow()->attempts, bkRow()->error_message])->toBe(['unknown', 2, 'The carrier cannot be reached.']);
});

it('refuses another payload for a booking whose outcome is unknown', function (): void {
    bkOutcome(bkCommand(meta: ['fail' => 'timeout']));

    expect(bkOutcome(bkCommand(city: 'Tula')))->toBe('common.idempotency_conflict')
        ->and(RecordingCarrier::calls())->toBe(['book fulfillment:1 1'])
        ->and(bkRow()->state)->toBe('unknown');
});

it('refuses a reference another process is booking under a live lease', function (): void {
    app(BookingJournal::class)->claim(bkCommand());

    expect(bkOutcome(bkCommand()))->toBe('in_progress')
        ->and(RecordingCarrier::calls())->toBe([])
        ->and([bkRow()->state, bkRow()->attempts])->toBe([BookingState::Pending->value, 1]);
});

it('dispatches ShipmentBooked once, after the commit', function (): void {
    Event::fake([ShipmentBooked::class]);

    bkBook(bkCommand());
    bkBook(bkCommand());

    Event::assertDispatchedTimes(ShipmentBooked::class, 1);
    Event::assertDispatched(ShipmentBooked::class, fn (ShipmentBooked $e): bool => [$e->reference, $e->carrier, $e->tracking]
        === ['fulfillment:1', RecordingCarrier::KEY, 'TRK-fulfillment:1']);
});

it('dispatches nothing for a rejection or an unknown outcome', function (): void {
    Event::fake([ShipmentBooked::class]);

    bkOutcome(bkCommand('f:1', ['fail' => 'reject']));
    bkOutcome(bkCommand('f:2', ['fail' => 'timeout']));

    Event::assertNotDispatched(ShipmentBooked::class);
});
