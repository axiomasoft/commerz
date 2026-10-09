<?php

declare(strict_types=1);

use Commerz\Shipping\Bookings\Internal\BookingJournal;
use Commerz\Shipping\Tests\Fixtures\RecordingCarrier;
use Commerz\Testkit\Concurrency;

require_once __DIR__.'/Helpers.php';

// C1–C4 of knowledge/shipping-design#booking (SHP-03) across processes. The recording carrier sleeps in every call so
// the competing processes arrive while the lease is held; its log is shared through flock.

uses(BkEnvironment::class);

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    bkSetUp();
});

afterEach(fn () => bkTruncate());

it('lets one process call the carrier for one reference (C1)', function (): void {
    $outcomes = Concurrency::run(4, fn (): string => bkOutcome(bkCommand(meta: ['sleep_ms' => 1000])), timeoutSeconds: 60);

    expect(bkTally($outcomes))->toBe(['booked TRK-fulfillment:1' => 1, 'in_progress' => 3])
        ->and(RecordingCarrier::calls())->toBe(['book fulfillment:1 1'])
        ->and([bkRow()->state, bkRow()->attempts])->toBe(['booked', 1]);
});

it('books different references side by side (C2)', function (): void {
    $outcomes = Concurrency::run(4, fn (int $worker): string => bkOutcome(bkCommand("f:{$worker}", ['sleep_ms' => 300])), timeoutSeconds: 60);

    expect($outcomes)->toBe(['booked TRK-f:0', 'booked TRK-f:1', 'booked TRK-f:2', 'booked TRK-f:3'])
        ->and(RecordingCarrier::calls('book'))->toHaveCount(4);
});

it('recovers an unknown booking once with find and never books it twice (C3)', function (): void {
    $command = bkCommand(meta: ['fail' => 'timeout', 'found' => true, 'sleep_ms' => 500]);

    expect(bkOutcome($command))->toBe('shipping.booking_outcome_unknown')
        ->and(bkRow()->state)->toBe('unknown');

    $outcomes = Concurrency::run(4, fn (): string => bkOutcome($command), timeoutSeconds: 60);

    expect(bkTally($outcomes))->toBe(['booked TRK-fulfillment:1' => 1, 'in_progress' => 3])
        ->and(RecordingCarrier::calls('book'))->toBe(['book fulfillment:1 1'])
        ->and(RecordingCarrier::calls('find'))->toBe(['find fulfillment:1 2'])
        ->and([bkRow()->state, bkRow()->attempts])->toBe(['booked', 2]);
});

it('takes over a lapsed lease with find first, in one process only (C4)', function (): void {
    $command = bkCommand(meta: ['sleep_ms' => 500]);
    app(BookingJournal::class)->claim($command); // an owner that died before calling the carrier
    bkExpireLease();

    $outcomes = Concurrency::run(4, fn (): string => bkOutcome($command), timeoutSeconds: 60);

    expect(bkTally($outcomes))->toBe(['booked TRK-fulfillment:1' => 1, 'in_progress' => 3])
        ->and(RecordingCarrier::calls())->toBe(['find fulfillment:1 2', 'book fulfillment:1 2'])
        ->and([bkRow()->state, bkRow()->attempts])->toBe(['booked', 2]);
});
