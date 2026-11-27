<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Pagination\Cursor;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Orders\Data\ListOrders;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Orders\Exceptions\OrderNotFound;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

beforeEach(function (): void {
    Commerce::fake()->sellables->add(slsSnapshot('fake:a', '100.00'));
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->web = slsChannel();
});

function oqPlace(ChannelId $channel, ?string $customer = null): OrderView
{
    $cart = slsCartWith($channel, ['fake:a' => 1]);

    if ($customer !== null) {
        slsCarts()->setCustomer(new SetCartCustomer($cart->id, CustomerRef::fromString($customer)));
    }

    return slsPlace($cart);
}

/**
 * @param  list<OrderView>  $orders
 * @return list<string>
 */
function oqIds(array $orders): array
{
    return array_map(static fn (OrderView $order): string => $order->id->value, $orders);
}

it('finds an order by id and by its number in its store', function (): void {
    $order = oqPlace($this->web);
    $store = app(Channels::class)->find($this->web)->store;
    $other = app(Channels::class)->find(slsChannel('shop', 'S-'))->store;

    expect(slsOrders()->find($order->id))->toEqual($order)
        ->and(slsOrders()->findByNumber($store, $order->number))->toEqual($order)
        ->and(fn () => slsOrders()->findByNumber($other, $order->number))->toThrow(OrderNotFound::class)
        ->and(fn () => slsOrders()->find(OrderId::generate()))->toThrow(OrderNotFound::class, 'Order')
        ->and(fn () => slsOrders()->history(OrderId::generate()))->toThrow(OrderNotFound::class);
});

it('lists the newest orders first with filters and a keyset cursor', function (): void {
    $shop = slsChannel('shop', 'S-');
    $first = oqPlace($this->web, 'crm:1');
    $second = oqPlace($shop, 'crm:2');
    $third = oqPlace($this->web, 'crm:1');

    $page = slsOrders()->list(new ListOrders(limit: 2));
    $rest = slsOrders()->list(new ListOrders(after: $page->next, limit: 2));

    expect(oqIds($page->items))->toBe(oqIds([$third, $second]))
        ->and($page->next)->not->toBeNull()
        ->and(oqIds($rest->items))->toBe(oqIds([$first]))
        ->and($rest->next)->toBeNull()
        ->and(oqIds(slsOrders()->list(new ListOrders(channel: $this->web))->items))->toBe(oqIds([$third, $first]))
        ->and(oqIds(slsOrders()->list(new ListOrders(customer: CustomerRef::fromString('crm:2')))->items))->toBe(oqIds([$second]))
        ->and(oqIds(slsOrders()->list(new ListOrders(state: OrderState::Placed, paymentState: PaymentState::Unpaid))->items))->toHaveCount(3)
        ->and(slsOrders()->list(new ListOrders(state: OrderState::Cancelled))->items)->toBe([])
        ->and(fn () => slsOrders()->list(new ListOrders(after: Cursor::after('nonsense'))))->toThrow(InvalidCommand::class, 'cursor')
        ->and(fn () => new ListOrders(limit: 0))->toThrow(InvalidCommand::class);
});

it('keeps the history of an order', function (): void {
    $order = oqPlace($this->web);
    $history = slsOrders()->history($order->id);

    expect($history)->toHaveCount(1)
        ->and($history[0]->order)->toEqual($order->id)
        ->and($history[0]->type)->toBe('placed')
        ->and($history[0]->actor)->toBeNull()
        ->and($history[0]->data['number'])->toBe($order->number);
});
