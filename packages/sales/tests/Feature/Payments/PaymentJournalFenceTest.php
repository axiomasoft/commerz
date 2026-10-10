<?php

declare(strict_types=1);

use Commerz\Operations\Contracts\Transactions;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderRow;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Commerz\Sales\Payments\Internal\PaymentJournal;
use Commerz\Sales\Payments\Internal\PaymentRow;
use Commerz\Sales\Payments\Internal\PaymentTransitions;
use Commerz\Testkit\Commerce;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Helpers.php';

uses(SlsEnvironment::class);

// D33: an answer is written only under the attempt it was asked under, so an owner whose lease was taken over never
// overwrites the new one; an event that moved the payment meanwhile does not fence it off, the transitions decide.

beforeEach(function (): void {
    slsCommitted();
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(slsSnapshot('fake:a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $order = slsPlace(slsCartWith(slsChannel(), ['fake:a' => 1]));
    $this->journal = app(PaymentJournal::class);
    $this->claimed = $this->journal->get(slsOrderPayment($order, 'initiating', $order->amountDue->amountMinor));
    $this->capture = fn (OrderRow $order, PaymentRow $current): PaymentRow => $this->journal->write(
        $order,
        $current,
        PaymentTransitions::apply($current, PaymentStatus::Captured) ?? $current,
    );
});

afterEach(fn () => slsTruncate());

function fnLease(PaymentId $id): void
{
    app(Transactions::class)->run(static function () use ($id): void {
        $journal = app(PaymentJournal::class);
        $payment = $journal->get($id->value);
        app(OrderLocks::class)->lock($payment->order);
        $journal->lease($journal->lock($id));
    });
}

it('writes the answer of the attempt that still holds the payment', function (): void {
    $written = $this->journal->finish($this->claimed, 1, $this->capture);

    expect([$written->status, $written->attempts])->toBe([PaymentStatus::Captured, 1]);
});

it('writes nothing for an attempt whose lease was taken over', function (): void {
    fnLease($this->claimed->id);

    $current = $this->journal->finish($this->claimed, 1, $this->capture);

    expect([$current->status, $current->attempts, $current->captured])->toBe([PaymentStatus::Initiating, 2, 0])
        ->and(DB::table('cmz_payments')->value('status'))->toBe('initiating');
});

it('applies the answer of its attempt to a payment an event moved meanwhile', function (string $status): void {
    DB::table('cmz_payments')->update(['status' => $status]);

    expect($this->journal->finish($this->claimed, 1, $this->capture))->status->toBe(PaymentStatus::Captured)->attempts->toBe(1);
})->with(['pending', 'failed', 'authorized']);
