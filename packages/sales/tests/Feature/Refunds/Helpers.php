<?php

declare(strict_types=1);

use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\Uuid7;
use Commerz\Money\Money;
use Commerz\Sales\Orders\Data\OrderEventView;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Payments\Data\PaymentView;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentEventType;
use Commerz\Sales\Refunds\Data\IssueRefund;
use Commerz\Sales\Refunds\Data\RefundId;
use Commerz\Sales\Refunds\Data\RefundView;
use Commerz\Sales\Returns\Data\ReturnId;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Data\Compiler\TableNames;

require_once __DIR__.'/../Helpers.php';

/** A manual payment of `$amount` (the balance by default) captured by an operator's event. */
function rfCaptured(OrderView $order, ?string $amount = null): PaymentView
{
    $payment = slsPay($order, amount: $amount === null ? null : Money::of($amount, $order->currency));
    slsPayments()->recordEvent(new RecordPaymentEvent('manual', 'bank-'.$payment->id->value, PaymentEventType::Captured, paymentReference: $payment->reference));

    return slsPayments()->find($payment->id);
}

/** A payment of the order captured at the recording gateway, so its refunds follow the gateway's scenario. */
function rfRecorded(OrderView $order): PaymentView
{
    return slsPay($order, 'recording', meta: ['status' => 'captured']);
}

function rfIssue(OrderView $order, string $amount, ?PaymentView $payment = null, ?ReturnId $return = null, ?string $key = null, ?string $reason = null): RefundView
{
    return slsRefunds()->issue(new IssueRefund(
        $order->id,
        Money::of($amount, $order->currency),
        $payment?->id,
        $return,
        $reason,
        $key === null ? null : IdempotencyKey::of($key),
    ));
}

/** A refund of the payment left `initiating` by its process, claimed `$seconds` ago. */
function rfInitiating(PaymentView $payment, int $amountMinor, int $seconds): RefundId
{
    $id = Uuid7::generate();
    $at = now('UTC')->subSeconds($seconds)->format('Y-m-d H:i:s.uP');

    DB::table(app(TableNames::class)->table('refunds'))->insert([
        'id' => $id,
        'order_id' => $payment->order->value,
        'payment_id' => $payment->id->value,
        'gateway' => $payment->gateway,
        'reference' => 'refund:'.$id,
        'status' => 'initiating',
        'currency' => $payment->amount->currency->code,
        'amount_minor' => $amountMinor,
        'fingerprint' => str_repeat('0', 64),
        'claimed_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    return new RefundId($id);
}

/** @return list<array<string, mixed>> payloads of the outbox messages of the type, oldest first */
function rfOutbox(string $type): array
{
    return DB::table(app(TableNames::class)->table('outbox_messages'))->where('type', $type)->orderBy('id')->get()
        ->map(static fn (object $message): array => json_decode($message->payload, true))->all();
}

/** @return list<string> */
function rfHistory(OrderView $order): array
{
    return array_map(static fn (OrderEventView $event): string => $event->type, slsOrders()->history($order->id));
}
