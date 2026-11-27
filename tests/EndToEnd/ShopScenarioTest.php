<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\EndToEnd\Support\CommittedDatabase;
use Commerz\EndToEnd\Support\ShopWorld;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Inventory\Facades\Inventory;
use Commerz\Inventory\Items\Data\StockItemId;
use Commerz\Inventory\Stock\Data\StockLevelView;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Outbox\Events\IntegrationEventDelivered;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Stock\Exceptions\InsufficientStock;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\PriceLists\Data\ListPriceLists;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Promotions\Facades\Promotions;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Checkout\Exceptions\QuoteChanged;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\Fulfillments\Data\BookFulfillment;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Fulfillments\Data\MarkFulfillmentDelivered;
use Commerz\Sales\Fulfillments\Enums\FulfillmentStatus;
use Commerz\Sales\Orders\Data\ListOrders;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentEventType;
use Commerz\Sales\Refunds\Data\IssueRefund;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Returns\Data\ReceiveReturn;
use Commerz\Sales\Returns\Data\RequestReturn;
use Illuminate\Support\Facades\Event;

// The shop of assembly-design#shop-scenario on the seven real packages and nothing else: the catalog sells, the
// inventory reserves and restocks, pricing prices and taxes, promotions discount and count, shipping prices and books,
// sales places, takes the money, ships and gives it back. Payments, refunds, booking and the relay refuse to run inside a
// transaction, so the data is committed and truncated around each test.

beforeEach(fn () => CommittedDatabase::prepare());

afterEach(fn () => CommittedDatabase::truncate());

/**
 * A cart of the shop for a known customer, shipping to Moscow, with the lines as [sellable, quantity].
 *
 * @param  list<array{SellableRef, int}>  $lines
 */
function shopCart(ShopWorld $world, array $lines, string $customer = 'crm:alice'): CartId
{
    $cart = Sales::carts()->create(new CreateCart(CommerceContext::for($world->channel, Currency::of('RUB'), 'ru')))->id;
    Sales::carts()->setCustomer(new SetCartCustomer($cart, CustomerRef::fromString($customer)));

    foreach ($lines as [$sellable, $quantity]) {
        Sales::carts()->addLine(new AddCartLine($cart, $sellable, $quantity));
    }

    Sales::carts()->setAddress(new SetCartAddress($cart, AddressRole::Shipping, new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 1'], 'Alice', '+70000000000')));

    return $cart;
}

function shopLevel(ShopWorld $world, StockItemId $item): StockLevelView
{
    return Inventory::stock()->level($item, $world->location);
}

function shopLine(OrderView $order, SellableRef $sellable): OrderLineId
{
    foreach ($order->lines as $line) {
        if ($line->sellable->equals($sellable)) {
            return $line->id;
        }
    }

    throw new LogicException("The order has no line of {$sellable}.");
}

function rub(string $amount): Money
{
    return Money::of($amount, 'RUB');
}

it('sells from cart to full refund on the real packages', function (): void {
    // The shop hands promotion usage back on a full refund too; the default policy (D13) restores only on cancellation
    // and expiry, so a refund keeps the coupon spent unless the host opts in.
    config(['commerz-promotions.restore_on' => ['cancelled', 'expired', 'refunded']]);
    $world = ShopWorld::build();

    // 1. The cart: two T-shirts S and a mug, the courier, the coupon.
    $cart = shopCart($world, [[$world->small, 2], [$world->mug, 1]]);
    $options = Sales::carts()->deliveryOptions($cart);
    Sales::carts()->selectDelivery(new SelectDelivery($cart, ShopWorld::COURIER));
    Sales::carts()->enterCode(new EnterCode($cart, ShopWorld::COUPON));

    expect(array_map(static fn ($option): string => $option->methodCode, $options))->toBe([ShopWorld::COURIER])
        ->and($options[0]->price)->toEqual(rub('300.00'));

    // 2. The quote, by the stages of docs/architecture/extension.md (prices → promotions → delivery → tax):
    //    lines: S 2 × 1000.00 = 2000.00, mug 1 × 500.00 = 500.00; subtotal 2500.00.
    //    cart promotions by priority, then code: `autumn-10` −10% of 2500.00 = −250.00, then `sale-100` −100.00.
    //    the order adjustments are shared over the line nets 2000 : 500 (D23): −250 → −200/−50, −100 → −80/−20.
    //    delivery: 300.00 (the goods, 2150.00 after discounts, stay below the free threshold of 5000.00).
    //    VAT 20% included, per line on the discounted base, half up: S 1720.00 × 20/120 = 286.666… → 286.67,
    //    mug 430.00 → 71.666… → 71.67, delivery 300.00 → 50.00; tax 408.34 inside the total.
    //    total = 1720.00 + 430.00 + 300.00 = 2450.00.
    $quote = Sales::carts()->quote($cart);
    [$small, $mug] = $quote->lines;
    $shares = static function (QuotedLine $line): array {
        return array_map(
            static fn (Adjustment $adjustment): string => $adjustment->amount->toDecimal(),
            array_values(array_filter($line->adjustments, static fn (Adjustment $adjustment): bool => $adjustment->target === AdjustmentTarget::Order)),
        );
    };

    expect($small->unitPrice)->toEqual(rub('1000.00'))
        ->and($mug->unitPrice)->toEqual(rub('500.00'))
        ->and(array_map(static fn ($adjustment): array => [$adjustment->amount->toDecimal(), $adjustment->couponCode], $quote->orderAdjustments))
        ->toBe([['-250.00', null], ['-100.00', ShopWorld::COUPON]])
        ->and($shares($small))->toBe(['-200.00', '-80.00'])
        ->and($shares($mug))->toBe(['-50.00', '-20.00'])
        ->and($small->total)->toEqual(rub('1720.00'))
        ->and($mug->total)->toEqual(rub('430.00'))
        ->and(array_map(static fn ($tax): array => [$tax->target, $tax->lineId, $tax->amount->toDecimal(), $tax->included], $quote->taxLines))->toBe([
            [AdjustmentTarget::Line, $small->lineId, '286.67', true],
            [AdjustmentTarget::Line, $mug->lineId, '71.67', true],
            [AdjustmentTarget::Delivery, null, '50.00', true],
        ])
        ->and($quote->delivery?->price)->toEqual(rub('300.00'))
        ->and($quote->totals->subtotal)->toEqual(rub('2500.00'))
        ->and($quote->totals->discountTotal)->toEqual(rub('350.00'))
        ->and($quote->totals->deliveryTotal)->toEqual(rub('300.00'))
        ->and($quote->totals->taxTotal)->toEqual(rub('408.34'))
        ->and($quote->total)->toEqual(rub('2450.00'));

    // 3. Placing reserves the stock and counts the coupon; the order keeps the quote it re-ran under its lock (its
    //    fingerprint carries the time of that run, so only the money is compared).
    $order = Sales::checkout()->place(new PlaceOrder($cart, $quote->total));

    expect($order->state)->toBe(OrderState::Placed)
        ->and($order->paymentState)->toBe(PaymentState::Unpaid)
        ->and($order->total)->toEqual($quote->total)
        ->and($order->totals)->toEqual($quote->totals)
        ->and($order->orderAdjustments)->toEqual($quote->orderAdjustments)
        ->and(array_map(static fn ($line): array => [$line->total, $line->adjustments, $line->taxLines], $order->lines))
        ->toEqual(array_map(static fn ($line): array => [$line->total, $line->adjustments, $line->taxLines], $quote->lines))
        ->and([shopLevel($world, $world->smallItem)->available, shopLevel($world, $world->smallItem)->reserved])->toBe([3, 2])
        ->and(shopLevel($world, $world->mugItem)->available)->toBe(9)
        ->and(Promotions::coupons()->findByCode(ShopWorld::COUPON)->used)->toBe(1)
        ->and(Promotions::promotions()->find($world->couponPromotion)->usage->orders)->toBe(1)
        ->and(Promotions::promotions()->find($world->automaticPromotion)->usage->orders)->toBe(1);

    // 4. One delivery price from the options, through the quote, into the order (A4 on every real stage).
    expect($order->delivery?->price)->toEqual($options[0]->price)
        ->and($order->delivery?->methodCode)->toBe(ShopWorld::COURIER);

    // 5. The manual gateway's capture of the whole amount pays the order.
    $payment = Sales::payments()->initiate(new InitiatePayment($order->id, 'manual'));
    Sales::payments()->recordEvent(new RecordPaymentEvent('manual', 'bank-tx-1', PaymentEventType::Captured, paymentReference: $payment->reference));

    expect(Sales::orders()->find($order->id)->paymentState)->toBe(PaymentState::Paid);

    // 6. Fulfilling every line consumes the reservation; the manual carrier books with our tracking number.
    $smallLine = shopLine($order, $world->small);
    $mugLine = shopLine($order, $world->mug);
    $fulfillment = Sales::fulfillments()->create(new CreateFulfillment($order->id, [new LineQuantity($smallLine, 2), new LineQuantity($mugLine, 1)]));

    expect([shopLevel($world, $world->smallItem)->onHand, shopLevel($world, $world->smallItem)->reserved])->toBe([3, 0])
        ->and([shopLevel($world, $world->mugItem)->onHand, shopLevel($world, $world->mugItem)->reserved])->toBe([9, 0]);

    $shipped = Sales::fulfillments()->book(new BookFulfillment($fulfillment->id, meta: ['tracking' => 'RU-TRACK-1']));
    $delivered = Sales::fulfillments()->markDelivered(new MarkFulfillmentDelivered($fulfillment->id));

    expect($shipped->status)->toBe(FulfillmentStatus::Shipped)
        ->and([$shipped->carrier, $shipped->methodCode, $shipped->tracking])->toBe(['manual', ShopWorld::COURIER, 'RU-TRACK-1'])
        ->and($delivered->status)->toBe(FulfillmentStatus::Delivered)
        ->and(Sales::orders()->find($order->id)->state)->toBe(OrderState::Completed);

    // 7. One T-shirt comes back to the shelf. Its money (1720.00 / 2 = 860.00) leaves the coupon counted; the rest of the
    //    capture (2450.00 − 860.00 = 1590.00) refunds the order in full and hands the coupon back.
    $return = Sales::returns()->request(new RequestReturn($order->id, [new LineQuantity($smallLine, 1)], reason: 'size'));
    Sales::returns()->receive(new ReceiveReturn($return->id, [new LineQuantity($smallLine, 1)], ShopWorld::LOCATION));

    expect(shopLevel($world, $world->smallItem)->onHand)->toBe(4)
        ->and(Sales::orders()->find($order->id)->fulfillmentState)->toBe(FulfillmentState::PartiallyReturned);

    $partial = Sales::refunds()->issue(new IssueRefund($order->id, rub('860.00'), return: $return->id));

    expect($partial->status)->toBe(RefundStatus::Succeeded)
        ->and(Sales::orders()->find($order->id)->paymentState)->toBe(PaymentState::PartiallyRefunded)
        ->and(Promotions::coupons()->findByCode(ShopWorld::COUPON)->used)->toBe(1);

    $rest = Sales::refunds()->issue(new IssueRefund($order->id, rub('1590.00')));
    $final = Sales::orders()->find($order->id);

    expect($rest->status)->toBe(RefundStatus::Succeeded)
        ->and($final->refunded)->toEqual(rub('2450.00'))
        ->and($final->paymentState)->toBe(PaymentState::Refunded)
        ->and(Promotions::coupons()->findByCode(ShopWorld::COUPON)->used)->toBe(0)
        ->and(Promotions::promotions()->find($world->couponPromotion)->usage->orders)->toBe(0)
        ->and(Promotions::promotions()->find($world->automaticPromotion)->usage->orders)->toBe(0)
        // A refund gives money back, never goods (REF-01): the shelf keeps what the return restocked.
        ->and(shopLevel($world, $world->smallItem)->onHand)->toBe(4);

    // 8. The relay hands the outbox to subscribers in the order the facts happened, each once.
    $delivered = [];
    Event::listen(IntegrationEventDelivered::class, static function (IntegrationEventDelivered $event) use (&$delivered): void {
        $delivered[] = [$event->event->eventId, $event->event->type];
    });
    $this->pendingArtisan('commerz:outbox:relay', ['--once' => true])->assertSuccessful();

    $types = array_column($delivered, 1);

    expect(array_unique(array_column($delivered, 0)))->toHaveCount(count($delivered))
        ->and($types)->toBe([
            // ShopWorld receives S, M and the mug.
            'inventory.level_changed',
            'inventory.level_changed',
            'inventory.level_changed',
            // Placing reserves S and the mug, then records the order.
            'inventory.level_changed',
            'inventory.level_changed',
            'sales.order_placed',
            'sales.payment_state_changed',
            // Fulfilling consumes both reservations.
            'inventory.level_changed',
            'inventory.level_changed',
            'sales.order_completed',
            'sales.fulfillment_shipped',
            // The return puts one S back on hand.
            'inventory.level_changed',
            'sales.return_received',
            'sales.payment_state_changed',
            'sales.refund_succeeded',
            'sales.payment_state_changed',
            'sales.refund_succeeded',
        ]);
});

it('places nothing when the stock is short', function (): void {
    $world = ShopWorld::build();
    $cart = shopCart($world, [[$world->medium, 3]]);
    Sales::carts()->selectDelivery(new SelectDelivery($cart, ShopWorld::COURIER));
    Sales::carts()->enterCode(new EnterCode($cart, ShopWorld::COUPON));
    $quote = Sales::carts()->quote($cart);

    expect(fn () => Sales::checkout()->place(new PlaceOrder($cart, $quote->total)))->toThrow(InsufficientStock::class);

    expect(Sales::orders()->list(new ListOrders)->items)->toBe([])
        ->and(shopLevel($world, $world->mediumItem)->reserved)->toBe(0)
        ->and(shopLevel($world, $world->mediumItem)->available)->toBe(2)
        ->and(Promotions::coupons()->findByCode(ShopWorld::COUPON)->used)->toBe(0)
        ->and(Promotions::promotions()->find($world->automaticPromotion)->usage->orders)->toBe(0);
});

it('refuses an order whose quote changed after the customer saw it', function (): void {
    $world = ShopWorld::build();
    $cart = shopCart($world, [[$world->mug, 1]]);
    Sales::carts()->selectDelivery(new SelectDelivery($cart, ShopWorld::COURIER));
    $quote = Sales::carts()->quote($cart);

    // 500.00 − 10% + 300.00 = 750.00; the mug then goes up to 600.00 (− 10% + 300.00 = 840.00).
    expect($quote->total)->toEqual(rub('750.00'));

    $retail = Pricing::priceLists()->list(new ListPriceLists)->items[0];
    Pricing::prices()->set(new SetPrice($retail->id, $world->mug, rub('600.00')));

    expect(fn () => Sales::checkout()->place(new PlaceOrder($cart, $quote->total)))->toThrow(QuoteChanged::class)
        ->and(Sales::carts()->quote($cart)->total)->toEqual(rub('840.00'))
        ->and(Sales::orders()->list(new ListOrders)->items)->toBe([])
        ->and(shopLevel($world, $world->mugItem)->reserved)->toBe(0);
});
