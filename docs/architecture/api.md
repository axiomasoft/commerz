# Public API conventions

Every Commerz package exposes the same shape of API as Chatom, AzGuard and
Vaulter: one root API class, domain contracts, command and query DTOs in,
immutable views and results out. This document fixes the rules and shows
abbreviated examples; exact signatures are owned by the code and its
`#[Api]`/`#[Spi]` markers.

## Rules

1. **One root API class per package** (`final readonly`, `#[Api]`), for
   example `Commerz\Commerz`, `Commerz\Inventory\Inventory`. It only returns
   domain contracts. The package facade is a thin proxy to it. With DI, inject
   the contract itself (`StockReservations $reservations`).
2. **Command in, immutable result out.** A mutation takes one `final readonly`
   command DTO (shape invariants validated in the constructor, named
   arguments, `with*()` modifiers) and returns a `*View` or `*Result`. Never an
   Eloquent model, Builder or model collection.
3. **Queries** are `Find*` (one item, throws `*NotFound`) and `List*` (cursor
   `Page<T>`). Filters are typed DTO fields, not arrays.
4. **Explicit context.** `CommerceContext` is passed into every pricing and
   sales operation; `ActorRef` goes into mutating commands where audit matters.
5. **Idempotency.** Mutations exposed to retries (`ReceiveStock`,
   `ReserveStock`, `PlaceOrder`, `RecordPaymentEvent`, coupon redemption)
   accept `?IdempotencyKey`. Same key and payload returns the original result;
   same key with another payload throws `IdempotencyConflict`.
6. **The command owns its invariant.** No low-level operations like
   `incrementReserved()`; `reserve()` locks, checks and writes the ledger
   itself (lesson of Medusa #16569 and Vendure 3.7.4).
7. **Errors** are subclasses of `CommerzException` with a stable machine
   `code()` (`inventory.insufficient_stock`), a `category()`
   (`validation|not_found|conflict|limit|unsupported|configuration|integration`)
   and scalar `context()`. HTTP rendering is not the core's job.
8. **Money and quantities** are value objects (`Money`, `Quantity`), never
   floats or strings.
9. **Extensible values** are addressed by a stable key (`acme.color_swatch`);
   a rule's configuration is a typed plugin DTO serialized with
   `config_version`.

## Markers and naming

- `#[Api(since: '0.1')]` marks stable callable surface;
  `#[Spi(kind: SpiKind::Implement|SpiKind::Call, since: '0.1')]` marks an
  extension point. A public type outside `Internal` without a marker fails the
  architecture gate.
- Public signatures contain no Eloquent models, `Builder`, `Request`, or
  floats for money and quantities.
- Commands are verb + noun (`ReserveStock`, `PlaceOrder`); queries are
  `Find*`/`List*`; results are `*View`, `*Result`, `Page<T>`.
- ID parameters name their owner (`$orderId`); amounts carry their unit
  (`$amountMinor`). Enum cases are PascalCase.

## Core: `Commerz`

`Commerz\Commerz`: `stores()`, `channels()`, `plugins()` (inspector),
`quotes()` (low-level `QuotePipeline`), `sellables()`.

```php
$store   = Commerz::stores()->create(new CreateStore(code: 'main', name: 'Main', defaultCurrency: Currency::of('RUB')));
$channel = Commerz::channels()->create(new CreateChannel(
    store: $store->id, code: 'web', name: 'Website',
    currencies: [Currency::of('RUB')], locales: ['ru', 'en'], pricesIncludeTax: true,
));

$ctx = CommerceContext::for($channel->id, Currency::of('RUB'), locale: 'ru')
    ->withCustomer(CustomerRef::of('user', '42'));   // groups come from CustomerDirectory

Commerz::plugins()->describe();                       // registry diagnostics: keys, source, version
```

Domain events leave through `Commerz\Operations\Contracts\CommittedEvents::dispatch()`: after the surrounding
transaction commits, at once outside one, never on rollback.

## Catalog: `Catalog`

`products()`, `variants()`, `options()`, `attributes()`, `productTypes()`,
`categories()`, `listings()`. Every operation is a command or query DTO in,
a view out; failures are `catalog.*` domain exceptions. Registers the
`SellableProvider` with key `variant`, so `SellableRef::of('variant', $variantId)`
goes into a cart and `Commerz::sellables()` resolves it to a snapshot:
purchasable in the context's channel at `context.at` (first failing reason of
`product_inactive`, `variant_archived`, `not_listed`, `not_available_yet`,
`no_longer_available`, `unsupported_kind`), options and name in the context's
locale, scalar non-retired attributes, categories with all ancestors, `kind`
from the type's `ProductKind`. The catalog holds no prices: the price comes
from `PriceResolver`.

Codes (options, option values, attributes, choices, categories) are lowercase
`[a-z0-9_.-]`; an attribute's `type` and `config` are fixed by `define`.

```php
Catalog::options()->define(new DefineOption('color', LocalizedText::of('en', 'Color'),
    ['red' => LocalizedText::of('en', 'Red'), 'blue' => LocalizedText::of('en', 'Blue')]));
Catalog::options()->define(new DefineOption('size', LocalizedText::of('en', 'Size'),
    ['s' => LocalizedText::of('en', 'S'), 'm' => LocalizedText::of('en', 'M')]));

Catalog::attributes()->define(new DefineAttribute('material', 'enum', LocalizedText::of('en', 'Material'),
    choices: ['cotton' => LocalizedText::of('en', 'Cotton')], filterable: true));
Catalog::attributes()->define(new DefineAttribute('density', 'measure', LocalizedText::of('en', 'Density'),
    config: ['unit' => 'gsm'], filterable: true));

$type = Catalog::productTypes()->create(new CreateProductType('apparel', LocalizedText::of('en', 'Apparel'),
    productAttributes: ['material', 'density'], variantAxes: ['color', 'size']));
$product = Catalog::products()->create(new CreateProduct($type->id, 'basic-tee', LocalizedText::of('en', 'Tee'),
    attributes: ['material' => 'cotton', 'density' => Measure::of('180', 'gsm')]));

$generated = Catalog::variants()->generate(new GenerateVariants(
    product: $product->id,
    axes: ['color' => ['red', 'blue'], 'size' => ['s', 'm']],
    skuPattern: 'TEE-{color}-{size}',
    except: [['color' => 'blue', 'size' => 's']],     // sparse set, not a full cartesian product
    dryRun: false,                                      // true plans only; skipped lists existing combinations
));
Catalog::listings()->publish(new PublishProduct($product->id, $channel->id));

$page = Catalog::products()->list(new ListProducts(
    channel: $channel->id,
    filters: [AttributeFilter::equals('material', 'cotton'), VariantFilter::axes(['color' => 'red', 'size' => 'm'])],
    first: 24,
));
```

## Inventory: `Inventory`

`locations()`, `items()`, `sources()`, `stock()` (receive, adjust, count,
levels), `reservations()` (the `StockReservations` port), `transfers()`,
`availability()` (the `StockAvailability` port), `movements()` (read-only
ledger).

```php
Inventory::items()->link(new LinkSellable(sellable: SellableRef::of('variant', $v->id), item: $item->id, quantityPerUnit: 1));
Inventory::stock()->receive(new ReceiveStock(item: $item->id, location: $wh->id, quantity: 50,
    reason: 'purchase', reference: 'po:123', idempotencyKey: IdempotencyKey::of('po:123:line:1')));

$result = Inventory::reservations()->reserve(new ReserveStock(
    demand: 'order:01J…', channel: $channel->id,
    lines: [new ReserveLine(SellableRef::of('variant', $v->id), quantity: 3)],
    expiresAt: null,
));                                                   // InsufficientStock: nothing is reserved
Inventory::reservations()->consume(new ConsumeReservation(
    demand: 'order:01J…', sellable: SellableRef::of('variant', $v->id), quantity: 3, reference: 'fulfillment:…',
));
```

Reservations are addressed by `demand` (the owner's reference), not by an
internal reservation id, so sales never needs inventory identifiers.

## Pricing: `Pricing`

`priceLists()`, `prices()`, `taxes()`. Implements `PriceResolver` and the
`tax` quote stage.

```php
Pricing::prices()->set(new SetPrice(
    priceList: $list->id, sellable: SellableRef::of('variant', $v->id),
    amount: Money::of('1990.00', 'RUB'), compareAt: Money::of('2490.00', 'RUB'),
    minQuantity: 1, customerGroup: null, validFrom: null, validUntil: null,
));
Pricing::taxes()->setRate(new SetTaxRate(taxClass: 'standard', zone: 'RU', rateBp: 2000));

[$price] = Pricing::prices()->resolveMany([new ResolvePrice(SellableRef::of('variant', $v->id), quantity: 2)], $ctx);
```

## Promotions: `Promotions`

`promotions()`, `coupons()`. A code entered into a cart is only a presented
code in `QuoteInput`; limits are consumed when the order is placed (D13).

```php
$promo = Promotions::promotions()->create(new CreatePromotion(
    code: 'autumn-10', name: 'Autumn −10%', channels: [$channel->id],
    activator: 'coupon', stacking: Stacking::Stackable, priority: 100,
    conditions: [new RuleSpec('cart.subtotal_at_least', ['amount' => Money::of('3000.00', 'RUB')])],
    effects:    [new RuleSpec('order.percent_off', ['rateBp' => 1000])],
    limits: new UsageLimits(total: 1000, perCustomer: 1), budget: Money::of('500000.00', 'RUB'),
    startsAt: $from, endsAt: $to,
));
Promotions::coupons()->generate(new GenerateCoupons(promotion: $promo->id, count: 500, pattern: 'AUT-####-####'));
$explain = Promotions::promotions()->explain(new ExplainPromotions($quoteInput));   // why applied or not
```

## Shipping: `Shipping`

`zones()`, `methods()`, `options()` (the `DeliveryOptions` port), `bookings()`
(the `ShipmentBooking` port, always outside a database transaction).

```php
Shipping::methods()->create(new CreateMethod(
    code: 'courier', name: LocalizedText::of(['ru' => 'Курьер']), zones: [$zone->id], channels: [$channel->id],
    rate: new RuleSpec('weight_table', ['steps' => [[1000, '300.00'], [5000, '500.00']], 'currency' => 'RUB']),
    carrier: 'manual',
));
$options = Shipping::options()->options(new DeliveryRequest($ctx, destination: $address, parcel: $parcel));
```

## Sales: `Sales`

`carts()`, `checkout()`, `orders()`, `payments()`, `fulfillments()`,
`returns()`, `refunds()`.

```php
$cart = Sales::carts()->create(new CreateCart($ctx));
Sales::carts()->addLine(new AddCartLine($cart->id, SellableRef::of('variant', $v->id), quantity: 2));
Sales::carts()->enterCode(new EnterCode($cart->id, 'AUTUMN'));
$quote = Sales::carts()->quote($cart->id);           // lines, explained adjustments, taxes, totals

$order = Sales::checkout()->place(new PlaceOrder(
    cart: $cart->id, idempotencyKey: IdempotencyKey::of($key),
    expectedTotal: $quote->total,                    // guards against price changes between display and submit
));                                                  // one transaction: snapshot + reserve + usage + outbox

Sales::payments()->initiate(new InitiatePayment($order->id, gateway: 'manual', amount: $order->total)); // after commit
Sales::fulfillments()->create(new CreateFulfillment($order->id, lines: [[$lineId, 2]], location: $wh->id));
Sales::returns()->receive(new ReceiveReturn($returnId, restock: [[$lineId, 0]])); // receiving ≠ restocking

// CRM: an order drafted by a manager without a customer cart
Sales::orders()->draft(new DraftOrder($ctx, lines: [new DraftLine(SellableRef::of('crm.service', '77'), quantity: 1)]));
```

States are independent enums: `OrderState` (`draft → placed → completed |
cancelled`), `PaymentState` (`unpaid → authorized → partially_paid → paid →
partially_refunded → refunded`), `FulfillmentState` (`unfulfilled →
partially_fulfilled → fulfilled → partially_returned → returned`).

## CRM embedding

A CRM installs `axiomasoft/commerz` + `axiomasoft/commerz-sales` and plugs in
its own sellables. Without catalog, inventory and pricing, the price comes
from `SellableSnapshot::$basePrice` (core `SnapshotPriceResolver`) and stock is
`UnlimitedStock`, selected explicitly in configuration.

```php
final class CrmServiceSellables implements SellableProvider
{
    public function key(): string { return 'crm.service'; }

    /** @return array<string, SellableSnapshot> keyed by (string) $ref */
    public function resolve(array $refs, CommerceContext $context): array { /* kind Service, no shipping */ }
}

final class CrmPlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest { return new PluginManifest(id: 'flex.crm-commerce', version: '1.0.0'); }
    public function register(PluginRegistrar $registrar): void { $registrar->sellableProvider(CrmServiceSellables::class); }
}
// config/commerz.php: 'stock' => 'unlimited', 'customers' => CrmCustomerDirectory::class
```

## Foundation audit corrections

All catalog list commands accept `first` from 1 to 200. Variant cursors validate
both the int64 position and the UUID before SQL. Numeric option and attribute
codes keep their identity in generation, filters and sellable snapshots.

A sellable snapshot's `options` remains a map of display labels to localized
values. If multiple axes resolve to the same display label, the catalog appends
`[option_code]` to disambiguate them, repeating the suffix if a literal label
already uses that key. This preserves every axis while retaining the existing
map contract and the labels of unambiguous options.
