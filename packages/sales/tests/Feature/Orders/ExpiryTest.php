<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Data\ReleaseReservation;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Orders\Data\DraftLine;
use Commerz\Sales\Orders\Data\DraftOrder;
use Commerz\Sales\Orders\Data\ExpireOrders;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Enums\CancelReason;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Schema\TableNames;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

// D32: sales owns the payment deadline (4320 minutes for orders from a cart); the stock never expires a reservation.

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(slsSnapshot('fake:a', '100.00'), slsSnapshot('fake:svc', '50.00', SellableKind::Service));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->web = slsChannel();
    $this->later = new DateTimeImmutable('+4321 minutes');
});

/** Moves the deadline of the order. */
function expDue(OrderView $order, string $at): void
{
    DB::table(app(TableNames::class)->table('orders'))->where('id', $order->id->value)->update(['payment_due_at' => new DateTimeImmutable($at)->format('Y-m-d H:i:s.uP')]);
}

/** @return list<string> */
function expIds(array $orders): array
{
    return array_map(static fn (OrderId|OrderView $order): string => $order instanceof OrderView ? $order->id->value : $order->value, $orders);
}

it('expires the unpaid orders past their deadline, earliest deadline first', function (): void {
    $cart = slsCartWith($this->web, ['fake:a' => 2]);
    slsCarts()->enterCode(new EnterCode($cart->id, 'promo:p1'));
    $first = slsPlace($cart);
    $second = slsPlace(slsCartWith($this->web, ['fake:svc' => 1], address: false));
    expDue($second, '-2 hours');
    expDue($first, '-1 hour');

    $report = slsOrders()->expireDue(new ExpireOrders);
    $order = slsOrders()->find($first->id);

    expect(expIds($report->expired))->toBe(expIds([$second, $first]))
        ->and($report->skipped)->toBe(0)
        ->and([$order->state, $order->cancelReason])->toBe([OrderState::Cancelled, CancelReason::Expired])
        ->and(slsOrders()->find($second->id)->cancelReason)->toBe(CancelReason::Expired)
        ->and($this->commerce->stock->released)->toEqual([new ReleaseReservation('order:'.$first->id->value)])
        ->and($this->commerce->promotions->restored)->toEqual([new RestorePromotionUsage('order:'.$first->id->value, RestoreReason::Expired)])
        ->and(array_map(static fn ($event): array => [$event->type, $event->actor, $event->data], array_slice(slsOrders()->history($first->id), 1)))
        ->toEqual([['cancelled', null, ['reason' => 'expired']]])
        ->and(slsOrders()->expireDue(new ExpireOrders)->expired)->toBe([]);
});

it('leaves orders before their deadline and expires them once it passes', function (): void {
    $order = slsPlace(slsCartWith($this->web, ['fake:a' => 1]));

    expect(slsOrders()->expireDue(new ExpireOrders)->expired)->toBe([])
        ->and(slsOrders()->expireDue(new ExpireOrders(new DateTimeImmutable('+4319 minutes')))->expired)->toBe([])
        ->and(expIds(slsOrders()->expireDue(new ExpireOrders($this->later))->expired))->toBe(expIds([$order]));
});

it('keeps an order with a payment that may still bring money', function (string $status): void {
    $order = slsPlace(slsCartWith($this->web, ['fake:a' => 1]));
    slsOrderPayment($order, $status, 10000, authorizedMinor: $status === 'authorized' ? 10000 : 0);

    $report = slsOrders()->expireDue(new ExpireOrders($this->later));

    expect($report->expired)->toBe([])
        ->and($report->skipped)->toBe(1)
        ->and(slsOrders()->find($order->id)->state)->toBe(OrderState::Placed)
        ->and($this->commerce->stock->released)->toBe([]);
})->with(['initiating', 'pending', 'requires_action', 'unknown', 'authorized']);

it('expires an order whose payments failed or were cancelled', function (): void {
    $order = slsPlace(slsCartWith($this->web, ['fake:a' => 1]));
    slsOrderPayment($order, 'failed', 10000);
    slsOrderPayment($order, 'cancelled', 10000);

    expect(expIds(slsOrders()->expireDue(new ExpireOrders($this->later))->expired))->toBe(expIds([$order]));
});

it('keeps an order with fulfilled units', function (): void {
    $order = slsPlace(slsCartWith($this->web, ['fake:a' => 2]));
    slsLedger()->fulfill($order->id, new LineQuantity(slsLine($order, 'fake:a'), 1));

    expect(slsOrders()->expireDue(new ExpireOrders($this->later)))->expired->toBe([])->skipped->toBe(1)
        ->and(slsOrders()->find($order->id)->state)->toBe(OrderState::Placed);
});

it('never expires a draft, which has no deadline', function (): void {
    $draft = slsOrders()->draft(new DraftOrder(slsContext($this->web), [new DraftLine(SellableRef::fromString('fake:svc'), 1)]));
    $order = slsPlace($draft->id);

    expect($order->paymentDueAt)->toBeNull()
        ->and(slsOrders()->expireDue(new ExpireOrders(new DateTimeImmutable('+10 years'))))->expired->toBe([])->skipped->toBe(0);
});

it('expires at most the limit in one pass', function (): void {
    $orders = array_map(fn (): OrderView => slsPlace(slsCartWith($this->web, ['fake:a' => 1])), range(1, 3));
    slsConfig(['orders' => ['expire_batch' => 2]]);

    expect(slsOrders()->expireDue(new ExpireOrders($this->later, limit: 1))->expired)->toHaveCount(1)
        ->and(slsOrders()->expireDue(new ExpireOrders($this->later))->expired)->toHaveCount(2)
        ->and(slsOrders()->find($orders[2]->id)->state)->toBe(OrderState::Cancelled)
        ->and(fn () => new ExpireOrders(limit: 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new ExpireOrders(limit: 1001))->toThrow(InvalidCommand::class);
});

it('runs one pass from the console and prints its outcome', function (): void {
    $order = slsPlace(slsCartWith($this->web, ['fake:a' => 1]));
    slsPlace(slsCartWith($this->web, ['fake:a' => 1]));
    expDue($order, '-1 minute');

    $this->artisan('commerz:sales:expire-orders', ['--limit' => '10'])
        ->expectsOutputToContain('expired 1, skipped 0')
        ->assertSuccessful();

    expect(slsOrders()->find($order->id)->state)->toBe(OrderState::Cancelled);

    $this->artisan('commerz:sales:expire-orders')->expectsOutputToContain('expired 0, skipped 0')->assertSuccessful();
    $this->artisan('commerz:sales:expire-orders', ['--limit' => 'all'])->assertFailed();
    $this->artisan('commerz:sales:expire-orders', ['--limit' => '1001'])->assertFailed();
});
