<?php

declare(strict_types=1);

use Commerz\Errors\UnsupportedContribution;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Data\PaymentView;
use Commerz\Sales\Payments\Data\ReconcilePayment;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Commerz\Sales\Payments\Exceptions\PaymentInProgress;
use Commerz\Sales\Payments\Exceptions\PaymentNotFound;
use Commerz\Sales\Payments\Exceptions\PaymentOutcomeUnknown;
use Commerz\Sales\Tests\Fixtures\RecordingGateway;
use Commerz\Testkit\Commerce;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Helpers.php';

uses(SlsEnvironment::class);

// Payments::reconcile (PAY-02): an unknown outcome is asked of the gateway the payment was made at, under a new attempt.

beforeEach(function (): void {
    slsCommitted();
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(slsSnapshot('fake:a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->order = slsPlace(slsCartWith(slsChannel(), ['fake:a' => 2]));
});

afterEach(fn () => slsTruncate());

it('learns the customer action of a pending payment during reconciliation', function (): void {
    $payment = slsPay($this->order, 'recording');
    RecordingGateway::scenario(['found' => 'requires_action']);

    $reconciled = slsPayments()->reconcile(new ReconcilePayment($payment->id));

    expect($reconciled->status)->toBe(PaymentStatus::RequiresAction)
        ->and($reconciled->actionUrl)->toBe('https://pay.example/3ds/'.$payment->reference)
        ->and(slsPayments()->find($payment->id)->actionUrl)->toBe($reconciled->actionUrl);
});

/** A recording payment whose initiate timed out. */
function rcUnknown(OrderView $order): PaymentView
{
    try {
        slsPay($order, 'recording', meta: ['fail' => 'timeout']);
    } catch (PaymentOutcomeUnknown $e) {
        return slsPayments()->find(new PaymentId($e->payment));
    }

    throw new LogicException('The payment did not time out.');
}

/** An attempt of the order left `initiating` by its process, claimed `$seconds` ago. */
function rcInitiating(OrderView $order, int $seconds, string $gateway = 'recording'): PaymentId
{
    $id = slsOrderPayment($order, 'initiating', $order->amountDue->amountMinor);
    DB::table('cmz_payments')->where('id', $id)->update([
        'gateway' => $gateway,
        'claimed_at' => now('UTC')->subSeconds($seconds)->format('Y-m-d H:i:s.uP'),
    ]);

    return new PaymentId($id);
}

function rcReconcile(PaymentId|PaymentView $payment): PaymentView
{
    return slsPayments()->reconcile(new ReconcilePayment($payment instanceof PaymentView ? $payment->id : $payment));
}

it('settles an unknown payment with what the gateway found', function (): void {
    $payment = rcUnknown($this->order);
    RecordingGateway::scenario(['found' => 'captured']);

    $settled = rcReconcile($payment);

    expect([$settled->status, $settled->attempts, $settled->externalId, $settled->errorCode])
        ->toBe([PaymentStatus::Captured, 2, 'ext-'.$payment->reference, null])
        ->and($settled->captured)->toEqual($payment->amount)
        ->and(slsOrders()->find($this->order->id)->paymentState)->toBe(PaymentState::Paid)
        ->and(RecordingGateway::calls())->toBe(['initiate '.$payment->reference, 'find '.$payment->reference]);
});

it('fails an unknown payment the gateway never created, so the order can be paid anew', function (): void {
    $payment = rcUnknown($this->order);

    $failed = rcReconcile($payment);

    expect([$failed->status, $failed->errorCode])->toBe([PaymentStatus::Failed, 'not_found_at_gateway'])
        ->and(slsPay($this->order)->amount)->toEqual($this->order->amountDue);
});

it('leaves an attempt being initiated to its process while the lease is fresh', function (): void {
    $id = rcInitiating($this->order, 5);

    expect(fn () => rcReconcile($id))->toThrow(PaymentInProgress::class)
        ->and(slsPayments()->find($id)->attempts)->toBe(1)
        ->and(RecordingGateway::calls())->toBe([]);
});

it('recovers an attempt whose process died once the lease lapses', function (): void {
    $id = rcInitiating($this->order, 121);
    RecordingGateway::scenario(['found' => 'authorized']);

    $recovered = rcReconcile($id);

    expect([$recovered->status, $recovered->attempts])->toBe([PaymentStatus::Authorized, 2])
        ->and(slsOrders()->find($this->order->id)->paymentState)->toBe(PaymentState::Authorized);
});

it('turns a lapsed attempt unknown when the gateway cannot be reached', function (): void {
    $id = rcInitiating($this->order, 121);
    RecordingGateway::scenario(['found' => 'fail']);

    expect(fn () => rcReconcile($id))->toThrow(PaymentOutcomeUnknown::class)
        ->and(slsPayments()->find($id))->status->toBe(PaymentStatus::Unknown)->attempts->toBe(2);
});

it('keeps a pending payment as it is when the gateway cannot be reached or lost it', function (): void {
    $payment = slsPay($this->order, 'recording');

    RecordingGateway::scenario(['found' => 'fail']);
    expect(fn () => rcReconcile($payment))->toThrow(PaymentOutcomeUnknown::class);

    RecordingGateway::scenario([]);
    expect(fn () => rcReconcile($payment))->toThrow(InvalidGatewayResult::class, 'vanished')
        ->and(slsPayments()->find($payment->id))->status->toBe(PaymentStatus::Pending)->attempts->toBe(3);

    RecordingGateway::scenario(['found' => 'captured']);
    expect(rcReconcile($payment)->status)->toBe(PaymentStatus::Captured);
});

it('returns a final payment without asking the gateway', function (string $status): void {
    $payment = slsPay($this->order, 'recording', meta: ['status' => $status]);
    RecordingGateway::reset();

    expect(rcReconcile($payment))->toEqual(slsPayments()->find($payment->id))
        ->and(RecordingGateway::calls())->toBe([]);
})->with(['captured', 'failed', 'cancelled']);

it('refuses to run inside a transaction or without the gateway of the payment', function (): void {
    $payment = rcUnknown($this->order);

    expect(fn () => app(Transactions::class)->run(fn () => rcReconcile($payment)))->toThrow(OperationInsideTransaction::class)
        ->and(fn () => rcReconcile(PaymentId::generate()))->toThrow(PaymentNotFound::class);

    DB::table('cmz_payments')->where('id', $payment->id->value)->update(['gateway' => 'gone']);

    expect(fn () => rcReconcile($payment))->toThrow(UnsupportedContribution::class)
        ->and(slsPayments()->find($payment->id))->status->toBe(PaymentStatus::Unknown)->attempts->toBe(1);
});
