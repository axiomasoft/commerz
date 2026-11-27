<?php

declare(strict_types=1);

use Commerz\Money\Currency;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Enums\PaymentStatus as S;
use Commerz\Sales\Payments\Internal\PaymentRow;
use Commerz\Sales\Payments\Internal\PaymentTransitions;
use PHPUnit\Framework\Assert;

// The transition table of knowledge/sales-design#payments: a payment of 100.00, amounts in minor units.

function ptPayment(S $status, int $authorized = 0, int $captured = 0): PaymentRow
{
    return new PaymentRow(
        new PaymentId('0190a000-0000-7000-8000-000000000001'),
        new OrderId('0190a000-0000-7000-8000-000000000002'),
        'manual',
        'payment:0190a000-0000-7000-8000-000000000001',
        null,
        $status,
        Currency::of('RUB'),
        10000,
        $authorized,
        $captured,
    );
}

it('moves a payment forward and ignores what would move it back', function (PaymentRow $current, S $observed, ?int $amount, ?array $expected): void {
    $next = PaymentTransitions::apply($current, $observed, $amount, $observed === S::RequiresAction ? 'https://pay.example/3ds' : null);

    expect($next === null ? null : [$next->status, $next->authorized, $next->captured])->toBe($expected);
})->with([
    // current, observed, amount, [status, authorized, captured] or null when ignored
    'initiating → pending' => [ptPayment(S::Initiating), S::Pending, null, [S::Pending, 0, 0]],
    'initiating → requires action' => [ptPayment(S::Initiating), S::RequiresAction, null, [S::RequiresAction, 0, 0]],
    'unknown → pending' => [ptPayment(S::Unknown), S::Pending, null, [S::Pending, 0, 0]],
    'pending again is ignored' => [ptPayment(S::Pending), S::Pending, null, null],
    'pending → requires action' => [ptPayment(S::Pending), S::RequiresAction, null, [S::RequiresAction, 0, 0]],
    'requires action → pending is ignored' => [ptPayment(S::RequiresAction), S::Pending, null, null],
    'authorized → pending is ignored' => [ptPayment(S::Authorized, 10000), S::Pending, null, null],
    'initiating → authorized in full' => [ptPayment(S::Initiating), S::Authorized, null, [S::Authorized, 10000, 0]],
    'pending → authorized in part' => [ptPayment(S::Pending), S::Authorized, 6000, [S::Authorized, 6000, 0]],
    'authorized again is ignored' => [ptPayment(S::Authorized, 10000), S::Authorized, null, null],
    'captured → authorized is ignored' => [ptPayment(S::Captured, 10000, 10000), S::Authorized, null, null],
    'pending → captured in full' => [ptPayment(S::Pending), S::Captured, null, [S::Captured, 10000, 10000]],
    'authorized → captured in part keeps the authorization' => [ptPayment(S::Authorized, 10000), S::Captured, 4000, [S::Captured, 10000, 4000]],
    'unknown → captured' => [ptPayment(S::Unknown), S::Captured, 10000, [S::Captured, 10000, 10000]],
    'captured more grows' => [ptPayment(S::Captured, 10000, 4000), S::Captured, 10000, [S::Captured, 10000, 10000]],
    'captured again is a repeat' => [ptPayment(S::Captured, 10000, 10000), S::Captured, 10000, null],
    'captured less never shrinks' => [ptPayment(S::Captured, 10000, 10000), S::Captured, 4000, null],
    'failed → captured: money is the truth' => [ptPayment(S::Failed), S::Captured, null, [S::Captured, 10000, 10000]],
    'cancelled → captured' => [ptPayment(S::Cancelled), S::Captured, 5000, [S::Captured, 5000, 5000]],
    'initiating → failed' => [ptPayment(S::Initiating), S::Failed, null, [S::Failed, 0, 0]],
    'unknown → failed' => [ptPayment(S::Unknown), S::Failed, null, [S::Failed, 0, 0]],
    'requires action → failed' => [ptPayment(S::RequiresAction), S::Failed, null, [S::Failed, 0, 0]],
    'authorized → failed is ignored' => [ptPayment(S::Authorized, 10000), S::Failed, null, null],
    'captured → failed is ignored' => [ptPayment(S::Captured, 10000, 10000), S::Failed, null, null],
    'failed again is ignored' => [ptPayment(S::Failed), S::Failed, null, null],
    'pending → cancelled' => [ptPayment(S::Pending), S::Cancelled, null, [S::Cancelled, 0, 0]],
    'authorized → cancelled keeps the record of the authorization' => [ptPayment(S::Authorized, 10000), S::Cancelled, null, [S::Cancelled, 10000, 0]],
    'captured → cancelled is ignored' => [ptPayment(S::Captured, 10000, 10000), S::Cancelled, null, null],
    'failed → cancelled is ignored' => [ptPayment(S::Failed), S::Cancelled, null, null],
    'cancelled → authorized is ignored' => [ptPayment(S::Cancelled), S::Authorized, null, null],
    'failed → pending is ignored' => [ptPayment(S::Failed), S::Pending, null, null],
]);

it('keeps the action url of a payment that requires an action', function (): void {
    expect(PaymentTransitions::apply(ptPayment(S::Initiating), S::RequiresAction, actionUrl: 'https://pay.example/3ds')?->actionUrl)
        ->toBe('https://pay.example/3ds');
});

it('updates the action url without moving an authorized or captured payment back', function (): void {
    $action = PaymentTransitions::apply(ptPayment(S::Pending), S::RequiresAction, actionUrl: 'https://pay.example/first');
    if (! $action instanceof PaymentRow) {
        Assert::fail('Expected a payment that requires action.');
    }
    $changed = PaymentTransitions::apply($action, S::RequiresAction, actionUrl: 'https://pay.example/next');
    if (! $changed instanceof PaymentRow) {
        Assert::fail('Expected the action url to change.');
    }

    expect($changed->actionUrl)->toBe('https://pay.example/next')
        ->and(PaymentTransitions::apply($changed, S::RequiresAction, actionUrl: 'https://pay.example/next'))->toBeNull()
        ->and(PaymentTransitions::apply(ptPayment(S::Authorized, 10000), S::RequiresAction, actionUrl: 'https://pay.example/next'))->toBeNull()
        ->and(PaymentTransitions::apply(ptPayment(S::Captured, 10000, 10000), S::RequiresAction, actionUrl: 'https://pay.example/next'))->toBeNull();
});

it('never observes the statuses of the protocol itself', function (S $observed): void {
    expect(fn () => PaymentTransitions::apply(ptPayment(S::Pending), $observed))->toThrow(LogicException::class);
})->with([S::Initiating, S::Unknown]);
