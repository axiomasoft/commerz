<?php

declare(strict_types=1);

// C5 of knowledge/shipping-design#booking: an owner whose lease was taken over cannot write its outcome (SHP-03).

use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Shipping\Bookings\Enums\BookingState;
use Commerz\Shipping\Bookings\Events\ShipmentBooked;
use Commerz\Shipping\Bookings\Internal\BookingClaim;
use Commerz\Shipping\Bookings\Internal\BookingJournal;
use Commerz\Shipping\Tests\Fixtures\RecordingCarrier;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Helpers.php';

uses(BkEnvironment::class);

beforeEach(function (): void {
    bkSetUp();
    $this->journal = app(BookingJournal::class);
    $this->shipment = new BookedShipment(RecordingCarrier::KEY, 'TRK-1');
});

afterEach(fn () => bkTruncate());

it('hands a lapsed lease to the next attempt in recovery', function (): void {
    $first = $this->journal->claim(bkCommand());
    bkExpireLease();
    $second = $this->journal->claim(bkCommand());

    expect([$first->mode, $first->attempt, $second->mode, $second->attempt, $second->id])
        ->toBe([BookingClaim::CREATE, 1, BookingClaim::RECOVER, 2, $first->id]);
});

it('writes nothing for an owner whose lease was taken over (C5)', function (): void {
    Event::fake([ShipmentBooked::class]);
    $stale = $this->journal->claim(bkCommand());
    bkExpireLease();
    $current = $this->journal->claim(bkCommand());

    expect($this->journal->finish($stale, BookingState::Booked, $this->shipment))->toBeFalse()
        ->and($this->journal->finish($stale, BookingState::Unknown, error: new RuntimeException('late')))->toBeFalse()
        ->and([bkRow()->state, bkRow()->attempts, bkRow()->tracking, bkRow()->error_code])->toBe(['pending', 2, null, null]);
    Event::assertNotDispatched(ShipmentBooked::class);

    expect($this->journal->finish($current, BookingState::Booked, $this->shipment))->toBeTrue()
        ->and([bkRow()->state, bkRow()->tracking])->toBe(['booked', 'TRK-1']);
    Event::assertDispatchedTimes(ShipmentBooked::class, 1);
});

it('writes an outcome once: a finished row is no longer pending', function (): void {
    $claim = $this->journal->claim(bkCommand());

    expect($this->journal->finish($claim, BookingState::Booked, $this->shipment))->toBeTrue()
        ->and($this->journal->finish($claim, BookingState::Unknown, error: new RuntimeException('late')))->toBeFalse()
        ->and(bkRow()->state)->toBe('booked');
});

it('keeps the error of a carrier storable', function (): void {
    $claim = $this->journal->claim(bkCommand());

    $this->journal->finish($claim, BookingState::Unknown, error: new RuntimeException("bad \xff byte\0 and ".str_repeat('x', 3000)));

    $message = bkRow()->error_message;

    if (! is_string($message)) {
        throw new RuntimeException('expected a stored error message');
    }

    expect(mb_check_encoding($message, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($message))->toBe(2000);
});
