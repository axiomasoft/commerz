<?php

declare(strict_types=1);

use Commerz\Identity\CustomerRef;
use Commerz\Ports\Privacy\Events\CustomerPersonalDataErased;
use Commerz\Shipping\Bookings\Contracts\Bookings;
use Commerz\Shipping\Bookings\Enums\BookingState;
use Illuminate\Contracts\Events\Dispatcher;

require_once __DIR__.'/Helpers.php';

uses(BkEnvironment::class);

beforeEach(fn () => bkSetUp());

afterEach(fn () => bkTruncate());

it('masks PII in shipping bookings for matching references while keeping carrier state', function (): void {
    bkBook(bkCommand('fulfillment:1'));
    bkOutcome(bkCommand('fulfillment:2', meta: ['fail' => 'timeout']));
    bkBook(bkCommand('fulfillment:3'));

    $erased = app(Bookings::class)->erasePersonalData(['fulfillment:1', 'fulfillment:2']);

    expect($erased)->toBe(2);

    $first = app(Bookings::class)->find('fulfillment:1');
    $second = app(Bookings::class)->find('fulfillment:2');
    $third = app(Bookings::class)->find('fulfillment:3');

    expect($first->meta)->toBe([])
        ->and($first->state)->toBe(BookingState::Booked)
        ->and($first->tracking)->toBe('TRK-fulfillment:1')
        ->and($second->errorMessage)->toBeNull()
        ->and($second->state)->toBe(BookingState::Unknown)
        ->and($third->meta)->toBe(['attempt' => 1]);
});

it('erases booking PII when CustomerPersonalDataErased is dispatched', function (): void {
    bkBook(bkCommand('fulfillment:evt-1'));

    app(Dispatcher::class)->dispatch(new CustomerPersonalDataErased(
        CustomerRef::of('crm', '1'),
        ['fulfillment:evt-1'],
    ));

    expect(app(Bookings::class)->find('fulfillment:evt-1')->meta)->toBe([]);
});
