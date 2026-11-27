<?php

declare(strict_types=1);

use Commerz\Sales\Common\Internal\OrderStatus;
use Commerz\Sales\Orders\Enums\FulfillmentState as F;
use Commerz\Sales\Orders\Enums\OrderState as O;
use Commerz\Sales\Orders\Enums\PaymentState as P;

// The derivation table of knowledge/sales-design#model: three units of 100.00, amounts in minor units.

it(
    'derives the three states from the sums of the order',
    /**
     * @param  array{O, int, int, int, int, int, int, int, int}  $sums
     * @param  array{O, P, F}  $expected
     */
    function (array $sums, array $expected): void {
        $current = $sums[0] ?? null;
        $quantity = $sums[1] ?? null;
        $cancelled = $sums[2] ?? null;
        $fulfilled = $sums[3] ?? null;
        $returned = $sums[4] ?? null;
        $amountDue = $sums[5] ?? null;
        $authorized = $sums[6] ?? null;
        $captured = $sums[7] ?? null;
        $refunded = $sums[8] ?? null;

        if (! $current instanceof O
            || ! is_int($quantity)
            || ! is_int($cancelled)
            || ! is_int($fulfilled)
            || ! is_int($returned)
            || ! is_int($amountDue)
            || ! is_int($authorized)
            || ! is_int($captured)
            || ! is_int($refunded)
        ) {
            throw new RuntimeException('Unexpected order-status derivation row.');
        }

        expect(OrderStatus::derive($current, $quantity, $cancelled, $fulfilled, $returned, $amountDue, $authorized, $captured, $refunded))->toBe($expected);
    })->with([
        // current, quantity, cancelled, fulfilled, returned, amountDue, authorized, captured, refunded
        'unpaid' => [[O::Placed, 3, 0, 0, 0, 30000, 0, 0, 0], [O::Placed, P::Unpaid, F::Unfulfilled]],
        'authorized' => [[O::Placed, 3, 0, 0, 0, 30000, 30000, 0, 0], [O::Placed, P::Authorized, F::Unfulfilled]],
        'partially paid' => [[O::Placed, 3, 0, 0, 0, 30000, 0, 10000, 0], [O::Placed, P::PartiallyPaid, F::Unfulfilled]],
        'paid' => [[O::Placed, 3, 0, 0, 0, 30000, 0, 30000, 0], [O::Placed, P::Paid, F::Unfulfilled]],
        'overpaid is paid' => [[O::Placed, 3, 0, 0, 0, 30000, 0, 40000, 0], [O::Placed, P::Paid, F::Unfulfilled]],
        'nothing due is paid' => [[O::Placed, 3, 0, 0, 0, 0, 0, 0, 0], [O::Placed, P::Paid, F::Unfulfilled]],
        'partially refunded' => [[O::Placed, 3, 0, 0, 0, 30000, 0, 30000, 10000], [O::Placed, P::PartiallyRefunded, F::Unfulfilled]],
        'refunded' => [[O::Placed, 3, 0, 0, 0, 30000, 0, 30000, 30000], [O::Placed, P::Refunded, F::Unfulfilled]],
        'partly cancelled' => [[O::Placed, 3, 1, 0, 0, 20000, 0, 0, 0], [O::Placed, P::Unpaid, F::Unfulfilled]],
        'partially fulfilled' => [[O::Placed, 3, 0, 1, 0, 30000, 0, 30000, 0], [O::Placed, P::Paid, F::PartiallyFulfilled]],
        'fulfilled and unpaid' => [[O::Placed, 3, 0, 3, 0, 30000, 0, 0, 0], [O::Placed, P::Unpaid, F::Fulfilled]],
        'fulfilled and partially paid' => [[O::Placed, 3, 0, 3, 0, 30000, 0, 10000, 0], [O::Placed, P::PartiallyPaid, F::Fulfilled]],
        'refunding a partial payment does not complete' => [[O::Placed, 3, 0, 3, 0, 30000, 0, 1000, 100], [O::Placed, P::PartiallyRefunded, F::Fulfilled]],
        'refunding all of a partial payment does not complete' => [[O::Placed, 3, 0, 3, 0, 30000, 0, 1000, 1000], [O::Placed, P::Refunded, F::Fulfilled]],
        'fulfilled and paid completes' => [[O::Placed, 3, 0, 3, 0, 30000, 0, 30000, 0], [O::Completed, P::Paid, F::Fulfilled]],
        'fulfilled and partially refunded completes' => [[O::Placed, 3, 0, 3, 0, 30000, 0, 30000, 5000], [O::Completed, P::PartiallyRefunded, F::Fulfilled]],
        'fulfilled and refunded completes' => [[O::Placed, 3, 0, 3, 0, 30000, 0, 30000, 30000], [O::Completed, P::Refunded, F::Fulfilled]],
        'free and fulfilled completes' => [[O::Placed, 3, 0, 3, 0, 0, 0, 0, 0], [O::Completed, P::Paid, F::Fulfilled]],
        'the rest cancelled after a fulfillment completes' => [[O::Placed, 3, 1, 2, 0, 20000, 0, 20000, 0], [O::Completed, P::Paid, F::Fulfilled]],
        'every unit cancelled cancels' => [[O::Placed, 3, 3, 0, 0, 0, 0, 0, 0], [O::Cancelled, P::Unpaid, F::Unfulfilled]],
        'cancelled with an authorization' => [[O::Placed, 3, 3, 0, 0, 0, 30000, 0, 0], [O::Cancelled, P::Authorized, F::Unfulfilled]],
        'cancelled with money is paid' => [[O::Placed, 3, 3, 0, 0, 0, 0, 30000, 0], [O::Cancelled, P::Paid, F::Unfulfilled]],
        'partially returned' => [[O::Completed, 3, 0, 3, 1, 30000, 0, 30000, 0], [O::Completed, P::Paid, F::PartiallyReturned]],
        'returned' => [[O::Completed, 3, 0, 3, 3, 30000, 0, 30000, 30000], [O::Completed, P::Refunded, F::Returned]],
        'returned with units outstanding is partial' => [[O::Placed, 3, 0, 2, 2, 30000, 0, 0, 0], [O::Placed, P::Unpaid, F::PartiallyReturned]],
        'completed is final' => [[O::Completed, 3, 0, 3, 0, 30000, 0, 0, 0], [O::Completed, P::Unpaid, F::Fulfilled]],
        'cancelled is final and a late capture is paid' => [[O::Cancelled, 3, 3, 0, 0, 0, 0, 30000, 0], [O::Cancelled, P::Paid, F::Unfulfilled]],
        'cancelled without money stays unpaid' => [[O::Cancelled, 3, 3, 0, 0, 0, 0, 0, 0], [O::Cancelled, P::Unpaid, F::Unfulfilled]],
    ]);
