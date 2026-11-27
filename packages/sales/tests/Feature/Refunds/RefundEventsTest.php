<?php

declare(strict_types=1);

use Commerz\Errors\IdempotencyConflict;
use Commerz\Money\Money;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Gateways\Data\GatewayRefundRequest;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Payments\Enums\PaymentEventOutcome;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentEvent;
use Commerz\Sales\Refunds\Data\ListRefunds;
use Commerz\Sales\Refunds\Data\RecordRefundEvent;
use Commerz\Sales\Refunds\Data\RefundView;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Refunds\Exceptions\RefundExceedsBalance;
use Commerz\Sales\Refunds\Exceptions\RefundNotFound;
use Commerz\Sales\Refunds\Exceptions\RefundOutcomeUnknown;
use Commerz\Sales\Tests\Fixtures\RecordingGateway;
use Commerz\Testkit\Commerce;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';

uses(SlsEnvironment::class);

// Refunds::recordEvent (PAY-01): a provider event about a refund is recorded once per (gateway, event id) in the inbox
// payments share, and moves the refund only forward: initiating, pending and unknown settle; succeeded and failed are final.

beforeEach(function (): void {
    slsCommitted();
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(slsSnapshot('fake:a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->web = slsChannel();
    $this->order = slsPlace(slsCartWith($this->web, ['fake:a' => 2]));
    $this->payment = rfRecorded($this->order);
    RecordingGateway::scenario(['refund' => 'pending']);
});

afterEach(fn () => slsTruncate());

it('learns an external id from a repeated success without refunding the payment twice', function (): void {
    $order = slsPlace(slsCartWith($this->web, ['fake:a' => 1]));
    $payment = rfCaptured($order, '10.00');
    $refund = rfIssue($order, '1.00', payment: $payment);
    $event = new RecordRefundEvent('manual', 'refund-id', true, refundReference: $refund->reference, externalRefundId: 'provider-refund');

    $ignored = slsRefunds()->recordEvent($event);
    $duplicate = slsRefunds()->recordEvent($event);
    $byId = slsRefunds()->recordEvent(new RecordRefundEvent('manual', 'refund-by-id', true, externalRefundId: 'provider-refund'));

    expect([$ignored->outcome, $duplicate->duplicate, $byId->outcome])->toBe([PaymentEventOutcome::Ignored, true, PaymentEventOutcome::Ignored])
        ->and(slsRefunds()->find($refund->id)->externalId)->toBe('provider-refund')
        ->and(slsPayments()->find($payment->id)->refunded)->toEqual(Money::of('1.00', 'RUB'));
});

function rfEvent(RefundView $refund, string $id, bool $succeeded = true, bool $byExternalId = false): RecordRefundEvent
{
    return $byExternalId
        ? new RecordRefundEvent('recording', $id, $succeeded, externalRefundId: $refund->externalId, payload: ['source' => 'webhook'])
        : new RecordRefundEvent('recording', $id, $succeeded, refundReference: $refund->reference, payload: ['source' => 'webhook']);
}

it('settles a pending refund as succeeded once, however often the event comes', function (): void {
    $refund = rfIssue($this->order, '200.00');

    $applied = slsRefunds()->recordEvent(rfEvent($refund, 'evt-1'));
    $duplicate = slsRefunds()->recordEvent(rfEvent($refund, 'evt-1'));

    expect([$applied->refund, $applied->duplicate, $applied->outcome, $applied->status])
        ->toEqual([$refund->id, false, PaymentEventOutcome::Applied, RefundStatus::Succeeded])
        ->and([$duplicate->duplicate, $duplicate->outcome, $duplicate->status])->toBe([true, PaymentEventOutcome::Applied, RefundStatus::Succeeded])
        ->and(slsPayments()->find($this->payment->id)->refunded)->toEqual(Money::of('200.00', 'RUB'))
        ->and(slsOrders()->find($this->order->id)->paymentState)->toBe(PaymentState::Refunded)
        ->and(rfOutbox('sales.refund_succeeded'))->toHaveCount(1)
        ->and(DB::table('cmz_payment_events')->where('external_event_id', 'evt-1')->first(['type', 'refund_id', 'payment_id', 'outcome']))
        ->toEqual((object) ['type' => 'refund_succeeded', 'refund_id' => $refund->id->value, 'payment_id' => null, 'outcome' => 'applied']);
});

it('fails a pending refund on a failure event, which frees its amount', function (): void {
    $refund = rfIssue($this->order, '200.00');

    $result = slsRefunds()->recordEvent(rfEvent($refund, 'evt-fail', succeeded: false, byExternalId: true));
    $failed = slsRefunds()->find($refund->id);
    RecordingGateway::scenario([]);
    $again = rfIssue($this->order, '200.00');

    expect([$result->outcome, $result->status])->toBe([PaymentEventOutcome::Applied, RefundStatus::Failed])
        ->and([$failed->status, $failed->errorCode])->toBe([RefundStatus::Failed, 'refund_failed_at_gateway'])
        ->and($again->status)->toBe(RefundStatus::Succeeded);
});

it('ignores an event that would move a final refund', function (): void {
    $refund = rfIssue($this->order, '200.00');
    slsRefunds()->recordEvent(rfEvent($refund, 'evt-ok'));

    $late = slsRefunds()->recordEvent(rfEvent($refund, 'evt-late-failure', succeeded: false));

    expect([$late->duplicate, $late->outcome, $late->status])->toBe([false, PaymentEventOutcome::Ignored, RefundStatus::Succeeded])
        ->and(slsRefunds()->find($refund->id)->errorCode)->toBeNull()
        ->and(DB::table('cmz_payment_events')->where('external_event_id', 'evt-late-failure')->value('outcome'))->toBe('ignored');
});

it('fills in the provider id of a refund and refuses one that does not fit', function (): void {
    RecordingGateway::scenario(['refund' => 'timeout']);

    try {
        rfIssue($this->order, '50.00');
    } catch (RefundOutcomeUnknown) {
    }

    $unknown = slsRefunds()->list(new ListRefunds($this->order->id))->items[0];
    RecordingGateway::scenario(['refund' => 'pending']);
    $other = rfIssue($this->order, '50.00');

    expect(fn () => slsRefunds()->recordEvent(new RecordRefundEvent('recording', 'evt-x', true, $unknown->reference, $other->externalId)))
        ->toThrow(fn (InvalidPaymentEvent $e) => expect($e->code())->toBe('sales.invalid_payment_event'))
        ->and(fn () => slsRefunds()->recordEvent(new RecordRefundEvent('recording', 'evt-y', true, $other->reference, 'ext-another')))
        ->toThrow(InvalidPaymentEvent::class);

    $result = slsRefunds()->recordEvent(new RecordRefundEvent('recording', 'evt-z', true, $unknown->reference, 'ext-provider-1'));

    expect([$result->status, slsRefunds()->find($unknown->id)->externalId])->toBe([RefundStatus::Succeeded, 'ext-provider-1'])
        ->and(DB::table('cmz_payment_events')->whereIn('external_event_id', ['evt-x', 'evt-y'])->count())->toBe(0);
});

it('records nothing for a refund it cannot find, and refuses the same event id with another content', function (): void {
    $refund = rfIssue($this->order, '200.00');
    slsRefunds()->recordEvent(rfEvent($refund, 'evt-1'));

    expect(fn () => slsRefunds()->recordEvent(new RecordRefundEvent('recording', 'evt-2', true, 'refund:missing')))->toThrow(RefundNotFound::class)
        ->and(fn () => slsRefunds()->recordEvent(new RecordRefundEvent('manual', 'evt-3', true, $refund->reference)))->toThrow(RefundNotFound::class)
        ->and(fn () => slsRefunds()->recordEvent(rfEvent($refund, 'evt-1', succeeded: false)))->toThrow(IdempotencyConflict::class)
        ->and(fn () => new RecordRefundEvent('recording', 'evt-4', true))->toThrow(InvalidPaymentEvent::class)
        ->and(DB::table('cmz_payment_events')->whereNotNull('refund_id')->count())->toBe(1);
});

it('gives the promotion usage back when an event completes the refund of the whole order', function (): void {
    $cart = slsCartWith($this->web, ['fake:a' => 1]);
    slsCarts()->enterCode(new EnterCode($cart->id, 'promo:p1'));
    $order = slsPlace($cart);
    rfRecorded($order);
    $refund = rfIssue($order, '90.00');

    $before = $this->commerce->promotions->restored;
    slsRefunds()->recordEvent(rfEvent($refund, 'evt-promo'));

    expect($before)->toBe([])
        ->and($this->commerce->promotions->restored)->toEqual([new RestorePromotionUsage('order:'.$order->id->value, RestoreReason::Refunded)]);
});

it('keeps the success the gateway answered after a failure event overtook it, so the money is not refunded twice', function (string $answer, RefundStatus $status): void {
    RecordingGateway::scenario($answer === 'succeeded' ? [] : ['refund' => $answer]);
    RecordingGateway::duringRefund(static function (GatewayRefundRequest $request): void {
        slsRefunds()->recordEvent(new RecordRefundEvent('recording', 'evt-race', false, $request->reference));
    });

    $refund = rfIssue($this->order, '200.00');
    RecordingGateway::duringRefund(null);

    expect($refund->status)->toBe($status);

    if ($status === RefundStatus::Succeeded) {
        expect([$refund->errorCode, $refund->externalId])->toBe([null, 'ext-'.$refund->reference])
            ->and(slsPayments()->find($this->payment->id)->refunded)->toEqual(Money::of('200.00', 'RUB'))
            ->and(fn () => rfIssue($this->order, '200.00'))->toThrow(RefundExceedsBalance::class)
            ->and(RecordingGateway::calls('refund'))->toBe(['refund '.$refund->reference]);
    }
})->with([
    'answered succeeded' => ['succeeded', RefundStatus::Succeeded],
    'answered pending' => ['pending', RefundStatus::Failed],
]);

it('takes a later success of a refund that failed: the money went back', function (): void {
    $refund = rfIssue($this->order, '200.00');
    slsRefunds()->recordEvent(rfEvent($refund, 'evt-failed', succeeded: false));

    $late = slsRefunds()->recordEvent(rfEvent($refund, 'evt-succeeded-late'));

    expect([$late->outcome, $late->status])->toBe([PaymentEventOutcome::Applied, RefundStatus::Succeeded])
        ->and(slsRefunds()->find($refund->id)->errorCode)->toBeNull()
        ->and(slsPayments()->find($this->payment->id)->refunded)->toEqual(Money::of('200.00', 'RUB'));
});

it('keeps a failed refund failed when its amount was refunded again before the late success', function (): void {
    $refund = rfIssue($this->order, '200.00');
    slsRefunds()->recordEvent(rfEvent($refund, 'evt-failed', succeeded: false));
    RecordingGateway::scenario([]);
    rfIssue($this->order, '200.00');

    $late = slsRefunds()->recordEvent(rfEvent($refund, 'evt-succeeded-late'));

    expect([$late->outcome, $late->status])->toBe([PaymentEventOutcome::Ignored, RefundStatus::Failed])
        ->and(slsPayments()->find($this->payment->id)->refunded)->toEqual(Money::of('200.00', 'RUB'))
        ->and(rfHistory($this->order))->toContain('refund_over_refunded')
        ->and(DB::table('cmz_payment_events')->where('external_event_id', 'evt-succeeded-late')->value('outcome'))->toBe('ignored');
});
