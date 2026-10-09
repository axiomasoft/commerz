<?php

declare(strict_types=1);

// The read side of the journal: Shipping::bookings() find and list.

use Commerz\Pagination\Cursor;
use Commerz\Shipping\Bookings\Contracts\Bookings;
use Commerz\Shipping\Bookings\Data\BookingView;
use Commerz\Shipping\Bookings\Data\ListBookings;
use Commerz\Shipping\Bookings\Enums\BookingState;
use Commerz\Shipping\Facades\Shipping as ShippingFacade;
use Commerz\Shipping\Shipping;
use Commerz\Shipping\Tests\Fixtures\RecordingCarrier;

require_once __DIR__.'/Helpers.php';

uses(BkEnvironment::class);

beforeEach(fn () => bkSetUp());

afterEach(fn () => bkTruncate());

/** @return list<string> */
function bkReferences(ListBookings $query): array
{
    return array_map(static fn (BookingView $view): string => $view->reference, app(Bookings::class)->list($query)->items);
}

it('reaches the journal from the root and the facade', function (): void {
    expect(app(Shipping::class)->bookings())->toBe(app(Bookings::class))
        ->and(ShippingFacade::bookings())->toBe(app(Bookings::class));
});

it('finds a booking by reference', function (): void {
    bkBook(bkCommand());
    $view = ShippingFacade::bookings()->find('fulfillment:1');

    expect([$view->reference, $view->carrier, $view->methodCode, $view->state, $view->attempts, $view->tracking, $view->labelRef, $view->meta])
        ->toBe(['fulfillment:1', RecordingCarrier::KEY, 'recorded', BookingState::Booked, 1, 'TRK-fulfillment:1', 'label:fulfillment:1', ['attempt' => 1]])
        ->and([$view->errorCode, $view->errorMessage, $view->bookedAt !== null, $view->id->value])
        ->toBe([null, null, true, bkRow()->id]);
});

it('shows the error of an unknown booking', function (): void {
    bkOutcome(bkCommand(meta: ['fail' => 'timeout']));
    $view = ShippingFacade::bookings()->find('fulfillment:1');

    expect([$view->state, $view->tracking, $view->meta, $view->errorCode, $view->errorMessage, $view->bookedAt])
        ->toBe([BookingState::Unknown, null, [], RuntimeException::class, 'The carrier timed out.', null]);
});

it('fails for a reference it never booked', function (): void {
    expect(mtFailure(fn () => ShippingFacade::bookings()->find('fulfillment:404')))->toBe('shipping.booking_not_found');
});

it('lists bookings oldest first, by state and page by page', function (): void {
    foreach (['a' => [], 'b' => ['fail' => 'timeout'], 'c' => ['fail' => 'reject'], 'd' => [], 'e' => ['fail' => 'timeout']] as $reference => $meta) {
        bkOutcome(bkCommand($reference, $meta));
    }

    $first = app(Bookings::class)->list(new ListBookings(limit: 2));
    $second = app(Bookings::class)->list(new ListBookings(after: $first->next, limit: 2));
    $third = app(Bookings::class)->list(new ListBookings(after: $second->next, limit: 2));

    expect(bkReferences(new ListBookings))->toBe(['a', 'b', 'c', 'd', 'e'])
        ->and(bkReferences(new ListBookings(BookingState::Unknown)))->toBe(['b', 'e'])
        ->and(bkReferences(new ListBookings(BookingState::Rejected)))->toBe(['c'])
        ->and(bkReferences(new ListBookings(BookingState::Pending)))->toBe([])
        ->and(array_map(fn (BookingView $v): string => $v->reference, [...$first->items, ...$second->items, ...$third->items]))
        ->toBe(['a', 'b', 'c', 'd', 'e'])
        ->and([$first->hasMore(), $second->hasMore(), $third->hasMore()])->toBe([true, true, false]);
});

it('rejects a cursor it did not produce', function (): void {
    expect(mtFailure(fn () => app(Bookings::class)->list(new ListBookings(after: Cursor::after('5:courier')))))->toBe('common.invalid_cursor @ after')
        ->and(mtFailure(fn () => new ListBookings(limit: 0)))->toBe('common.invalid_page_size @ limit');
});
