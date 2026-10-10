# Quick start: a shop

This walk-through builds a store on `axiomasoft/commerz-full` and sells one
order from cart to refund. Every snippet is the code of the verified end-to-end
scenario `tests/EndToEnd/ShopScenarioTest.php`, shortened; class names and
signatures are the real ones.

Every operation takes a typed command and returns a typed view. The package
roots are facades (`Commerz`, `Catalog`, `Inventory`, `Pricing`, `Promotions`,
`Shipping`, `Sales`) whose methods return the module contracts.

Imports are shown for the first snippet only; every command class lives in the
`Data` namespace of its module (`Commerz\Sales\Carts\Data\AddCartLine`), and
the [API reference](../reference/api/) lists them. `$lineId` below stands for an
order line id taken from `$order->lines`.

## 1. Store and channel

A **store** is a business unit (seller, currency, numbering); a **channel** is
a point of sale in it. See [stores and channels](../concepts/stores-and-channels).

```php
use Commerz\Facades\Commerz;
use Commerz\Money\Currency;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;

$rub = Currency::of('RUB');
$store = Commerz::stores()->create(new CreateStore('shop', 'Shop', $rub));
$channel = Commerz::channels()->create(
    new CreateChannel($store->id, 'web', 'Web', [$rub], ['ru'], pricesIncludeTax: true),
)->id;
```

## 2. Catalog

```php
use Commerz\Catalog\Facades\Catalog;
use Commerz\Measures\LocalizedText;

$text = fn (string $value) => LocalizedText::of('ru', $value);

Catalog::options()->define(new DefineOption('size', $text('Размер'), ['s' => $text('S'), 'm' => $text('M')]));
$apparel = Catalog::productTypes()->create(new CreateProductType('apparel', $text('Одежда'), variantAxes: ['size']));
$tee = Catalog::products()->create(new CreateProduct($apparel->id, 'tee', $text('Футболка')));
$small = Catalog::variants()->create(new CreateVariant($tee->id, 'TEE-S', ['size' => 's'], weightGrams: 300));
Catalog::listings()->publish(new PublishProduct($tee->id, $channel));
```

A variant's `sellable` is the `SellableRef` that stock, prices and carts use.
The command classes live next to their module, for example
`Commerz\Catalog\Products\Data\CreateProduct`; the
[API reference](../reference/api/catalog) lists them all.

## 3. Stock

```php
use Commerz\Inventory\Facades\Inventory;

$location = Inventory::locations()->create(new CreateLocation('msk', 'Moscow'))->id;
Inventory::sources()->assign(new AssignSource($channel, $location, 100));
$item = Inventory::items()->create(new CreateStockItem($small->sku))->id;
Inventory::items()->link(new LinkSellable($small->sellable, $item, 1));
Inventory::stock()->receive(new ReceiveStock($item, $location, 5));
```

## 4. Prices and tax

```php
use Commerz\Money\Money;
use Commerz\Pricing\Facades\Pricing;

$retail = Pricing::priceLists()->create(new CreatePriceList('retail', 'Retail', $rub, [$channel]));
Pricing::prices()->set(new SetPrice($retail->id, $small->sellable, Money::of('1000.00', 'RUB')));

Pricing::taxes()->defineClass(new DefineTaxClass('standard', 'Standard'));
Pricing::taxes()->defineZone(new DefineTaxZone('ru', 'Russia', [new TaxZoneMember('RU')]));
Pricing::taxes()->setRate(new SetTaxRate('standard', 'ru', 2000, 'vat')); // 2000 basis points = 20 %
```

## 5. Promotions

```php
use Commerz\Promotions\Facades\Promotions;

$promotion = Promotions::promotions()->create(new CreatePromotion(
    'sale-100', 'Sale -100', PromotionStage::Cart, 'coupon', [$channel],
    [new RuleSpec('order.fixed_off', ['amount' => Money::of('100.00', 'RUB')])],
    limits: new UsageLimits(total: 5, perCustomer: 1),
));
Promotions::coupons()->create(new CreateCoupon($promotion->id, 'SALE100'));
```

The activator (`automatic` or `coupon`) and the rule keys are
[extension kinds](../reference/extension-kinds).

## 6. Delivery

```php
use Commerz\Shipping\Facades\Shipping;

$zone = Shipping::zones()->create(new CreateZone('ru', 'Russia', [ZoneMember::country('RU')]));
Shipping::methods()->create(new CreateMethod('courier', $text('Курьер'), 'manual', [$channel], [
    new ZoneRate($zone->id, $rub, new RateSpec('flat', ['amount' => Money::of('300.00', 'RUB')]),
        Money::of('5000.00', 'RUB'), taxIncluded: true),
]));
```

`manual` is the built-in carrier: no network, the tracking number comes from
the booking's `meta`.

## 7. Cart, quote and order

The context — channel, currency, locale — is passed explicitly and is fixed for
the life of the cart.

```php
use Commerz\Context\CommerceContext;
use Commerz\Sales\Facades\Sales;

$cart = Sales::carts()->create(new CreateCart(CommerceContext::for($channel, $rub, 'ru')))->id;
Sales::carts()->addLine(new AddCartLine($cart, $small->sellable, 2));
Sales::carts()->setAddress(new SetCartAddress($cart, AddressRole::Shipping,
    new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 1'], 'Alice', '+70000000000')));
Sales::carts()->selectDelivery(new SelectDelivery($cart, 'courier'));
Sales::carts()->enterCode(new EnterCode($cart, 'SALE100'));

$quote = Sales::carts()->quote($cart);                      // what the buyer sees
$order = Sales::checkout()->place(new PlaceOrder($cart, $quote->total)); // what the buyer accepted
```

`PlaceOrder` carries the total the buyer accepted. Placing re-runs the quote
under the cart lock; if the total moved (a price changed meanwhile) it throws
`QuoteChanged` and creates nothing. Placing also reserves the stock and counts
the coupon, atomically; short stock throws `InsufficientStock` and leaves
nothing behind. Retrying `place` never creates a second order.

## 8. Payment, fulfillment, return, refund

```php
$payment = Sales::payments()->initiate(new InitiatePayment($order->id, 'manual'));
Sales::payments()->recordEvent(new RecordPaymentEvent(
    'manual', 'bank-tx-1', PaymentEventType::Captured, paymentReference: $payment->reference,
));

$fulfillment = Sales::fulfillments()->create(new CreateFulfillment($order->id, [new LineQuantity($lineId, 2)]));
Sales::fulfillments()->book(new BookFulfillment($fulfillment->id, meta: ['tracking' => 'RU-TRACK-1']));
Sales::fulfillments()->markDelivered(new MarkFulfillmentDelivered($fulfillment->id));

$return = Sales::returns()->request(new RequestReturn($order->id, [new LineQuantity($lineId, 1)], reason: 'size'));
Sales::returns()->receive(new ReceiveReturn($return->id, [new LineQuantity($lineId, 1)], 'msk'));
Sales::refunds()->issue(new IssueRefund($order->id, Money::of('860.00', 'RUB'), return: $return->id));
```

A payment event is idempotent by its provider event id: replaying the same
event does nothing. Payments, refunds, carrier booking and the outbox relay
refuse to run inside an open database transaction, because they call the
network.

## 9. Deliver the facts

Everything that happened is recorded in the transactional outbox as
`inventory.level_changed`, `sales.order_placed`, `sales.refund_succeeded` and so
on. A worker hands them to your listeners:

```bash
php artisan commerz:outbox:relay
```

See [events and outbox](../concepts/events-and-outbox) and
[run the workers](../how-to/run-workers).

## Next

- [Concepts](../concepts/stores-and-channels) for the model behind the calls.
- [Package pages](../packages/core/) for what each package offers.
- [Test with the testkit](../how-to/testing-with-testkit) to cover your own integration.
