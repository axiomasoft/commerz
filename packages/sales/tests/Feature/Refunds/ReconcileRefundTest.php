<?php

declare(strict_types=1);

use Commerz\Errors\UnsupportedContribution;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Sales\Common\Exceptions\OperationInsideTransaction;
use Commerz\Sales\Gateways\Exceptions\InvalidGatewayResult;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Refunds\Data\ReconcileRefund;
use Commerz\Sales\Refunds\Data\RefundId;
use Commerz\Sales\Refunds\Data\RefundView;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Refunds\Exceptions\RefundInProgress;
use Commerz\Sales\Refunds\Exceptions\RefundNotFound;
use Commerz\Sales\Refunds\Exceptions\RefundOutcomeUnknown;
use Commerz\Sales\Tests\Fixtures\RecordingGateway;
use Commerz\Testkit\Commerce;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';

uses(SlsEnvironment::class);

// Refunds::reconcile (PAY-02): an unknown outcome is asked of the gateway of the refund through findRefund, under a
// new attempt; a refund the provider never made fails, so its amount may be refunded anew.

beforeEach(function (): void {
    slsCommitted();
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(slsSnapshot('fake:a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->order = slsPlace(slsCartWith(slsChannel(), ['fake:a' => 2]));
    $this->payment = rfRecorded($this->order);
});

afterEach(fn () => slsTruncate());

/** A refund of the whole payment whose answer timed out. */
function rfUnknown(object $test): RefundView
{
    RecordingGateway::scenario(['refund' => 'timeout']);

    try {
        rfIssue($test->order, '200.00');
    } catch (RefundOutcomeUnknown $e) {
        RecordingGateway::scenario([]);

        return slsRefunds()->find(new RefundId($e->refund));
    }

    throw new LogicException('The refund did not time out.');
}

function rfReconcile(RefundId|RefundView $refund): RefundView
{
    return slsRefunds()->reconcile(new ReconcileRefund($refund instanceof RefundView ? $refund->id : $refund));
}

it('settles an unknown refund with what the gateway found', function (): void {
    $unknown = rfUnknown($this);
    RecordingGateway::scenario(['found_refund' => 'succeeded']);

    $refund = rfReconcile($unknown);

    expect([$refund->status, $refund->attempts, $refund->externalId, $refund->errorCode])
        ->toBe([RefundStatus::Succeeded, 2, 'ext-'.$refund->reference, null])
        ->and(slsPayments()->find($this->payment->id)->refunded)->toEqual(Money::of('200.00', 'RUB'))
        ->and(slsOrders()->find($this->order->id)->paymentState)->toBe(PaymentState::Refunded)
        ->and(RecordingGateway::calls('findRefund'))->toBe(['findRefund '.$refund->reference]);
});

it('fails an unknown refund the gateway never made, so the amount may be refunded anew', function (): void {
    $unknown = rfUnknown($this);

    $refund = rfReconcile($unknown);
    $again = rfIssue($this->order, '200.00');

    expect([$refund->status, $refund->errorCode])->toBe([RefundStatus::Failed, 'not_found_at_gateway'])
        ->and($again->status)->toBe(RefundStatus::Succeeded);
});

it('keeps an unknown refund unknown when the gateway cannot be reached', function (): void {
    $unknown = rfUnknown($this);
    RecordingGateway::scenario(['found_refund' => 'fail']);

    expect(fn () => rfReconcile($unknown))->toThrow(RefundOutcomeUnknown::class)
        ->and([slsRefunds()->find($unknown->id)->status, slsRefunds()->find($unknown->id)->attempts])->toBe([RefundStatus::Unknown, 2]);
});

it('keeps a pending refund pending when the gateway lost it', function (): void {
    RecordingGateway::scenario(['refund' => 'pending']);
    $pending = rfIssue($this->order, '200.00');
    RecordingGateway::scenario([]);

    expect(fn () => rfReconcile($pending))->toThrow(InvalidGatewayResult::class)
        ->and(slsRefunds()->find($pending->id)->status)->toBe(RefundStatus::Pending);
});

it('leaves a refund being issued to its process while the lease is fresh, and recovers it once it lapses', function (): void {
    $fresh = rfInitiating($this->payment, 5000, 5);
    $stale = rfInitiating($this->payment, 5000, 121);
    RecordingGateway::scenario(['found_refund' => 'succeeded']);

    expect(fn () => rfReconcile($fresh))->toThrow(fn (RefundInProgress $e) => expect($e->code())->toBe('sales.refund_in_progress'));

    $recovered = rfReconcile($stale);

    expect([$recovered->status, $recovered->attempts])->toBe([RefundStatus::Succeeded, 2])
        ->and(slsRefunds()->find($fresh)->attempts)->toBe(1);
});

it('returns a final refund without asking the gateway', function (): void {
    $refund = rfIssue($this->order, '20.00');

    expect(rfReconcile($refund))->toEqual($refund)
        ->and(RecordingGateway::calls('findRefund'))->toBe([]);
});

it('refuses to run inside a transaction, for a missing refund, or without the gateway of the refund', function (): void {
    $stale = rfInitiating($this->payment, 5000, 121);
    DB::table('cmz_refunds')->where('id', $stale->value)->update(['gateway' => 'gone']);

    expect(fn () => app(Transactions::class)->run(fn () => rfReconcile($stale)))->toThrow(OperationInsideTransaction::class)
        ->and(fn () => rfReconcile(RefundId::generate()))->toThrow(RefundNotFound::class)
        ->and(fn () => rfReconcile($stale))->toThrow(UnsupportedContribution::class)
        ->and(slsRefunds()->find($stale)->attempts)->toBe(1);
});
