<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Customers\Data\CustomerProfile;
use Commerz\Ports\Promotions\Exceptions\UsageLimitReached;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Carts\Enums\CartState;
use Commerz\Sales\Carts\Exceptions\CartNotFound;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Checkout\Exceptions\CartAlreadyPlaced;
use Commerz\Sales\Checkout\Exceptions\CartChanged;
use Commerz\Sales\Checkout\Exceptions\CartEmpty;
use Commerz\Sales\Checkout\Exceptions\DeliveryRequired;
use Commerz\Sales\Checkout\Exceptions\DuplicateOrderNumber;
use Commerz\Sales\Checkout\Exceptions\QuoteChanged;
use Commerz\Sales\Orders\Data\OrderLineView;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Orders\Events\OrderPlaced;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ArchiveChannel;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(
        slsSnapshot('fake:a', '100.00'),
        slsSnapshot('fake:b', '30.00'),
        slsSnapshot('fake:svc', '50.00', SellableKind::Service),
        slsSnapshot('fake:pdf', '20.00', SellableKind::Digital),
    );
    $this->web = slsChannel();
});

function plcTable(string $logical): string
{
    return app(TableNames::class)->table($logical);
}

/** @return array<string, int> rows per table the placement writes */
function plcCounts(): array
{
    return [
        'orders' => DB::table(plcTable('orders'))->count(),
        'order_lines' => DB::table(plcTable('order_lines'))->count(),
        'order_events' => DB::table(plcTable('order_events'))->count(),
        'outbox' => DB::table(plcTable('outbox_messages'))->count(),
        'receipts' => DB::table(plcTable('operation_receipts'))->count(),
    ];
}

function plcNothingWritten(): array
{
    return ['orders' => 0, 'order_lines' => 0, 'order_events' => 0, 'outbox' => 0, 'receipts' => 0];
}

it('snapshots the quote: lines, adjustments, taxes, delivery and totals', function (): void {
    $this->commerce->customers->add(new CustomerProfile(CustomerRef::of('crm', '7'), ['vip'], 'ivan@example.test', 'Ivan'));
    $cart = slsCartWith($this->web, ['fake:a' => 2, 'fake:svc' => 1]);
    slsCarts()->setCustomer(new SetCartCustomer($cart->id, CustomerRef::of('crm', '7'), ['vip']));
    slsCarts()->enterCode(new EnterCode($cart->id, 'promo:p1'));
    slsCarts()->enterCode(new EnterCode($cart->id, 'vat'));
    slsCarts()->selectDelivery(new SelectDelivery($cart->id, 'flat:300.00'));
    $quote = slsCarts()->quote($cart->id);

    $order = slsCheckout()->place(new PlaceOrder($cart->id, $quote->total, IdempotencyKey::of('k1')));

    expect($order->lines)->toHaveCount(2)
        ->and(array_map(static fn (OrderLineView $line): array => [
            $line->id->value, (string) $line->sellable, $line->sku, $line->name, $line->kind, $line->requiresShipping, $line->options,
            $line->taxClass, $line->weightGrams, $line->quantity, $line->unitPrice, $line->compareAt, $line->taxIncluded,
            $line->priceSource, $line->subtotal, $line->adjustments, $line->taxLines, $line->total,
        ], $order->lines))->toEqual(array_map(static fn (QuotedLine $line): array => [
            $line->lineId, (string) $line->sellable->ref, $line->sellable->sku, $line->sellable->name, $line->sellable->kind,
            $line->sellable->requiresShipping, $line->sellable->options, $line->sellable->taxClass, $line->sellable->weightGrams,
            $line->quantity, $line->unitPrice, $line->compareAt, $line->taxIncluded, $line->priceSource, $line->subtotal,
            $line->adjustments, $line->taxLines, $line->total,
        ], $quote->lines))
        ->and($order->lines[0]->id->value)->toBe($cart->lines[0]->id)
        ->and($order->orderAdjustments)->toEqual($quote->orderAdjustments)->not->toBe([])
        ->and($order->delivery)->toEqual($quote->delivery)
        ->and($order->deliveryAdjustments)->toEqual($quote->deliveryAdjustments)
        ->and($order->deliveryTaxLines)->toEqual(array_values(array_filter($quote->taxLines, static fn ($tax): bool => $tax->target === AdjustmentTarget::Delivery)))
        ->and($order->deliveryTaxLines)->toHaveCount(1)
        ->and($order->totals)->toEqual($quote->totals)
        ->and($order->total)->toEqual($quote->total)
        ->and($order->amountDue)->toEqual($quote->total)
        // The quote checked at placement is made again, at its own moment; its fingerprint carries that time.
        ->and($order->quoteFingerprint)->toMatch('/\A[0-9a-f]{64}\z/')
        ->and($order->codes)->toBe(['promo:p1', 'vat'])
        ->and($order->shippingAddress)->toEqual(slsAddress())
        ->and($order->billingAddress)->toBeNull()
        ->and([$order->customer, $order->customerGroups, $order->customerEmail, $order->customerName])
        ->toEqual([CustomerRef::of('crm', '7'), ['vip'], 'ivan@example.test', 'Ivan'])
        ->and([$order->state, $order->paymentState, $order->fulfillmentState, $order->cancelReason])
        ->toBe([OrderState::Placed, PaymentState::Unpaid, FulfillmentState::Unfulfilled, null])
        ->and([$order->origin, $order->cart->value, $order->channel, $order->currency->code, $order->locale])
        ->toEqual([CartKind::Cart, $cart->id->value, $this->web, 'RUB', 'ru'])
        ->and($order->number)->toMatch('/\AW-\d+\z/')
        ->and($order->authorized->isZero() && $order->captured->isZero() && $order->refunded->isZero())->toBeTrue()
        ->and($order->paymentDueAt?->getTimestamp())->toBe($order->placedAt->modify('+4320 minutes')->getTimestamp())
        ->and(slsOrders()->find($order->id))->toEqual($order)
        ->and(slsCarts()->find($cart->id)->state)->toBe(CartState::Converted);
});

it('keeps the snapshot when the price and the name of the sellable change after placement (ORD-02)', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $order = slsPlace(slsCartWith($this->web, ['fake:a' => 1]));

    $this->commerce->prices->set(SellableRef::fromString('fake:a'), Money::of('999.00', 'RUB'));
    $this->commerce->sellables->add(slsSnapshot('fake:a', '999.00', name: 'Renamed'));

    expect(slsOrders()->find($order->id))->toEqual($order)
        ->and($order->lines[0]->name)->toBe('Sellable a')
        ->and($order->lines[0]->unitPrice->toDecimal())->toBe('100.00');
});

it('places only at the total the buyer accepted', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $cart = slsCartWith($this->web, ['fake:a' => 1]);

    expect(fn () => slsCheckout()->place(new PlaceOrder($cart->id, Money::of('90.00', 'RUB'))))
        ->toThrow(QuoteChanged::class, 'not the expected 90.00 RUB')
        ->and(plcCounts())->toBe(plcNothingWritten());
});

it('refuses a cart that changed between its quote and its lock', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $cart = slsCartWith($this->web, ['fake:a' => 1]);
    slsCarts()->setCustomer(new SetCartCustomer($cart->id, CustomerRef::of('crm', '1')));
    // The customer directory is read after the quote and before the transaction: the moment a concurrent edit lands.
    app()->instance(CustomerDirectory::class, new class implements CustomerDirectory
    {
        public function find(CustomerRef $customer): ?CustomerProfile
        {
            DB::table(plcTable('carts'))->increment('version');

            return null;
        }
    });
    slsConfig([]);

    expect(fn () => slsPlace($cart, 'k1'))->toThrow(CartChanged::class)
        ->and(plcCounts())->toBe(plcNothingWritten())
        ->and(slsCarts()->find($cart->id)->state)->toBe(CartState::Open);
});

it('refuses an empty cart, an unknown cart and an unpurchasable line', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $empty = slsCartWith($this->web, []);
    $cart = slsCartWith($this->web, ['fake:a' => 1]);
    $this->commerce->sellables->add(slsSnapshot('fake:a', purchasable: false));

    expect(fn () => slsCheckout()->place(new PlaceOrder($empty->id, Money::zero('RUB'))))->toThrow(CartEmpty::class)
        ->and(fn () => slsCheckout()->place(new PlaceOrder(CartId::generate(), Money::zero('RUB'))))->toThrow(CartNotFound::class)
        ->and(fn () => slsCheckout()->place(new PlaceOrder($cart->id, Money::of('100.00', 'RUB'))))
        ->toThrow(SellableNotPurchasable::class, 'discontinued');
});

it('needs a shipping address and a delivery method for lines that ship', function (): void {
    $noAddress = slsCartWith($this->web, ['fake:a' => 1], address: false);
    $noMethod = slsCartWith($this->web, ['fake:a' => 1]);
    $services = slsCartWith($this->web, ['fake:svc' => 1, 'fake:pdf' => 1], address: false);

    expect(fn () => slsPlace($noAddress))->toThrow(ShippingAddressRequired::class)
        ->and(fn () => slsPlace($noMethod))->toThrow(DeliveryRequired::class)
        ->and(slsPlace($services)->delivery)->toBeNull();

    slsConfig(['checkout' => ['require_delivery' => false]]);

    expect(slsPlace($noMethod)->delivery)->toBeNull()
        ->and(fn () => slsPlace($noAddress))->toThrow(ShippingAddressRequired::class);
});

it('reserves the physical lines only, for the order and without a deadline', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $order = slsPlace(slsCartWith($this->web, ['fake:a' => 2, 'fake:svc' => 1, 'fake:b' => 3, 'fake:pdf' => 1]));
    $reserved = $this->commerce->stock->reserved;

    expect($reserved)->toHaveCount(1)
        ->and($reserved[0]->demand)->toBe('order:'.$order->id->value)
        ->and($reserved[0]->channel)->toEqual($this->web)
        ->and($reserved[0]->expiresAt)->toBeNull()
        ->and(array_map(static fn ($line): array => [(string) $line->sellable, $line->quantity], $reserved[0]->lines))
        ->toBe([['fake:a', 2], ['fake:b', 3]]);

    slsPlace(slsCartWith($this->web, ['fake:svc' => 1], address: false));

    expect($this->commerce->stock->reserved)->toHaveCount(1);
});

it('rolls the whole placement back when stock is short', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $this->commerce->stock->limit(SellableRef::fromString('fake:a'), 1);
    $cart = slsCartWith($this->web, ['fake:a' => 2]);

    expect(fn () => slsPlace($cart, 'k1'))->toThrow(InsufficientStock::class)
        ->and(plcCounts())->toBe(plcNothingWritten())
        ->and(slsCarts()->find($cart->id)->state)->toBe(CartState::Open);
});

it('counts the applied promotions of the quote, and rolls back when a limit is reached', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $cart = slsCartWith($this->web, ['fake:a' => 1]);
    slsCarts()->enterCode(new EnterCode($cart->id, 'promo:p1'));
    $quote = slsCarts()->quote($cart->id);

    $order = slsPlace($cart);
    $consumed = $this->commerce->promotions->consumed['order:'.$order->id->value];

    expect($consumed->applied)->toEqual($quote->appliedPromotions())
        ->and($consumed->applied[0]->discount->toDecimal())->toBe('10.00')
        ->and($consumed->customer)->toBeNull();

    $this->commerce->promotions->limit('p2', 0);
    $limited = slsCartWith($this->web, ['fake:b' => 1]);
    slsCarts()->enterCode(new EnterCode($limited->id, 'promo:p2'));
    $before = plcCounts();

    expect(fn () => slsPlace($limited))->toThrow(UsageLimitReached::class)
        ->and(plcCounts())->toBe($before)
        ->and(slsCarts()->find($limited->id)->state)->toBe(CartState::Open);
});

it('returns the same order for a repeat with the same key and reserves once (ORD-01)', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $cart = slsCartWith($this->web, ['fake:a' => 1]);
    $total = slsCarts()->quote($cart->id)->total;

    $first = slsCheckout()->place(new PlaceOrder($cart->id, $total, IdempotencyKey::of('k1')));
    $second = slsCheckout()->place(new PlaceOrder($cart->id, $total, IdempotencyKey::of('k1')));

    expect($second)->toEqual($first)
        ->and($this->commerce->stock->reserved)->toHaveCount(1)
        ->and(plcCounts()['orders'])->toBe(1)
        ->and(fn () => slsCheckout()->place(new PlaceOrder($cart->id, Money::of('1.00', 'RUB'), IdempotencyKey::of('k1'))))
        ->toThrow(IdempotencyConflict::class)
        ->and(fn () => slsCheckout()->place(new PlaceOrder($cart->id, $total, IdempotencyKey::of('k2'))))
        ->toThrow(CartAlreadyPlaced::class);
});

it('refuses a placed cart without a key, naming the order', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $cart = slsCartWith($this->web, ['fake:a' => 1]);
    $order = slsPlace($cart);

    try {
        slsPlace($cart);
        $this->fail('placed twice');
    } catch (CartAlreadyPlaced $e) {
        expect($e->order)->toBe($order->id->value)
            ->and($e->code())->toBe('sales.cart_already_placed');
    }
});

it('records the placement in the journal and the outbox and announces it after commit', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    Event::fake([OrderPlaced::class]);
    $cart = slsCartWith($this->web, ['fake:a' => 2]);
    slsCarts()->setCustomer(new SetCartCustomer($cart->id, CustomerRef::of('crm', '9')));

    $order = slsPlace($cart);
    $message = DB::table(plcTable('outbox_messages'))->sole();

    expect($message->type)->toBe('sales.order_placed')
        ->and($message->aggregate_ref)->toBe('order:'.$order->id->value)
        ->and(json_decode($message->payload, true))->toEqual([
            'order_id' => $order->id->value,
            'number' => $order->number,
            'channel_id' => $this->web->value,
            'customer_ref' => 'crm:9',
            'total_minor' => 20000,
            'currency' => 'RUB',
        ])
        ->and(array_map(static fn ($event): array => [$event->type, $event->data], slsOrders()->history($order->id)))
        ->toEqual([['placed', ['number' => $order->number, 'total_minor' => 20000, 'currency' => 'RUB']]]);
    Event::assertDispatched(OrderPlaced::class, static fn (OrderPlaced $event): bool => $event->order->equals($order->id) && $event->number === $order->number);
});

it('fails on a number the store already has and keeps the first order', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false, 'number_generator' => 'duplicate']]);
    $first = slsPlace(slsCartWith($this->web, ['fake:a' => 1]));
    $second = slsCartWith($this->web, ['fake:a' => 1]);

    expect($first->number)->toBe('DUP-1')
        ->and(fn () => slsPlace($second, 'k2'))->toThrow(DuplicateOrderNumber::class, "'DUP-1'")
        ->and(plcCounts()['orders'])->toBe(1)
        ->and(plcCounts()['receipts'])->toBe(0)
        ->and(slsCarts()->find($second->id)->state)->toBe(CartState::Open);

    // Another store may use the same number.
    expect(slsPlace(slsCartWith(slsChannel('shop'), ['fake:a' => 1]))->number)->toBe('DUP-1');
});

it('refuses to place a cart of an archived channel', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $cart = slsCartWith($this->web, ['fake:a' => 1]);
    app(Channels::class)->archive(new ArchiveChannel($this->web));

    expect(fn () => slsPlace($cart))->toThrow(ChannelArchived::class)
        ->and(plcCounts())->toBe(plcNothingWritten());
});

it('keeps texts the core does not bound in full: names, customer details, tax class and locale', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);
    $long = slsSnapshot('fake:long', name: str_repeat('Длинное имя ', 30));
    $this->commerce->sellables->add(new SellableSnapshot($long->ref, $long->sku, $long->name, $long->kind, false, basePrice: $long->basePrice, taxClass: str_repeat('t', 100)));
    $customer = CustomerRef::of('crm', 'long');
    $this->commerce->customers->add(new CustomerProfile($customer, [], str_repeat('e', 300).'@example.test', str_repeat('N', 300)));
    $locale = 'en'.str_repeat('-abcdef', 6);
    $cart = slsCarts()->create(new CreateCart(CommerceContext::for($this->web, Currency::of('RUB'), $locale)));
    slsCarts()->addLine(new AddCartLine($cart->id, SellableRef::fromString('fake:long'), 1));
    slsCarts()->setCustomer(new SetCartCustomer($cart->id, $customer));

    $order = slsPlace($cart);

    expect($order->lines[0]->name)->toBe($long->name)
        ->and($order->lines[0]->taxClass)->toBe(str_repeat('t', 100))
        ->and($order->customerName)->toBe(str_repeat('N', 300))
        ->and($order->customerEmail)->toBe(str_repeat('e', 300).'@example.test')
        ->and($order->locale)->toBe($locale)
        ->and(slsCarts()->find($cart->id)->locale)->toBe($locale);
});

it('numbers the orders of a store whose prefix starts with a hyphen', function (): void {
    slsConfig(['checkout' => ['require_delivery' => false]]);

    expect(slsPlace(slsCartWith(slsChannel('hy', '-'), ['fake:a' => 1]))->number)->toMatch('/\A-\d+\z/');
});
