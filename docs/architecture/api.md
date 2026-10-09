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

`locations()`, `items()` (stock items and sellable links), `sources()`
(channel → location with priority), `stock()` (`StockLevels`: receive, adjust,
count with `expectedOnHand`, safety stock, levels), `reservations()` (the
`StockReservations` port plus `find` and `expire`), `transfers()` (create →
dispatch → receive, or cancel a draft), `availability()` (the
`StockAvailability` port), `movements()` (read-only ledger). The package also
implements the `StockReturns` port.

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

app(StockReturns::class)->restock(new RestockStock(               // StockReturns: explicit, idempotent per reference
    reference: 'return:01J…', channel: $channel->id,
    lines: [new RestockLine(SellableRef::of('variant', $v->id), quantity: 1)], locationCode: 'msk-1',
));

$t = Inventory::transfers()->create(new CreateTransfer($wh->id, $pvz->id, [new TransferLine($item->id, 5)]));
Inventory::transfers()->dispatch(new DispatchTransfer($t->id));                       // takes the stock off the source
Inventory::transfers()->receive(new ReceiveTransfer($t->id, [new TransferReceipt($item->id, received: 4, lost: 1)]));
```

`ConsumeReservation::$locationCode` ships only from that location (null lets
inventory choose); untracked and unlimited sellables hold no physical stock
and consume successfully with a location hint too. Consumption is not
idempotent per reference: the caller deduplicates its fulfilment document.
A transfer holds its stock at the source as a reservation
of demand `transfer:{id}` from creation; goods in transit live on the
transfer's lines, not on a level. An item with unresolved lines on a draft or
in-transit transfer cannot be archived or changed to `untracked`, even when
its levels are empty. Fully received or lost lines no longer block these
changes. `commerz:inventory:reconcile` verifies the
ledger, reservations and transfers (see *Inventory lock order* in
`invariants.md`).

Reservations are addressed by `demand` (the owner's reference), not by an
internal reservation id, so sales never needs inventory identifiers.

## Pricing: `Pricing`

`priceLists()`, `prices()`, `taxes()`. Implements the `PriceResolver` port and
the `tax` quote stage; declares the `tax_calculator` SPI (built-in `rates`).

```php
$list = Pricing::priceLists()->create(new CreatePriceList(
    code: 'retail', name: 'Retail', currency: Currency::of('RUB'), channels: [$channel->id],
    priority: 0, taxInclusion: TaxInclusion::AsChannel,
));
Pricing::prices()->set(new SetPrice(
    priceList: $list->id, sellable: SellableRef::of('variant', $v->id),
    amount: Money::of('1990.00', 'RUB'), compareAt: Money::of('2490.00', 'RUB'),
    minQuantity: 1, customerGroup: null, validFrom: null, validUntil: null,
));                                              // same key and window: updates the amount
Pricing::prices()->setMany(new SetPrices($list->id, [/* SetPrice… */]));   // 1..1000, atomic
Pricing::prices()->reschedule(new ReschedulePrice($priceId, validFrom: null, validUntil: $at));
Pricing::prices()->remove(new RemovePrice($priceId));
Pricing::prices()->list(new ListPrices($list->id));                          // Page<PriceView>

[$price] = Pricing::prices()->resolveMany([new ResolvePrice(SellableRef::of('variant', $v->id), quantity: 2)], $ctx);
$why = Pricing::prices()->explain(new ExplainPrice(SellableRef::of('variant', $v->id), quantity: 2, context: $ctx));
// PriceExplanation: the ResolvedPrice, every candidate with a CandidateVerdict, fallbackUsed

Pricing::taxes()->defineClass(new DefineTaxClass(code: 'standard', name: 'Standard'));
Pricing::taxes()->defineZone(new DefineTaxZone(code: 'ru', name: 'Russia', members: [new TaxZoneMember('RU')]));
Pricing::taxes()->setRate(new SetTaxRate(taxClass: 'standard', zone: 'ru', rateBp: 2200, code: 'vat', validFrom: $from));
Pricing::taxes()->rescheduleRate(new RescheduleTaxRate($rateId, validFrom: $from, validUntil: null));
Pricing::taxes()->zoneFor(new Address('RU', 'RU-MOW'));                      // ?TaxZoneView
```

- Price lists: one currency, `priority` (higher wins), `TaxInclusion`
  (`Included`, `Excluded`, `AsChannel` = the channel's `pricesIncludeTax`),
  channels assigned through `Channels::assertActive`; lists are archived,
  never deleted. Prices are current configuration and are deleted physically
  (an order keeps its snapshot, ORD-02).
- Selection (D24, PRC-02): list priority DESC → lowest applicable amount →
  customer group → min quantity DESC → list code → price id. `explain` returns
  the same decision with a `CandidateVerdict` per candidate (`Selected`,
  `Outranked`, `GroupMismatch`, `BelowMinQuantity`, `OutsideWindow`).
- Without an applicable price the sellable's own price is used when
  `fallback_to_sellable` is on and the currency matches; otherwise
  `prices.price_not_found` (core).
- Tax: a `SetTaxRate` zone is a tax zone code (a set of countries or regions),
  not a country. Windows are `[validFrom, validUntil)`; a rate change by date
  is `reschedule` of the old rate then `setRate` of the new one in one
  `Transactions::run`.
- Views: `PriceListView`, `PriceView`, `TaxClassView`, `TaxZoneView`,
  `TaxRateView`.
- Events after commit: `PriceListChanged`, `PricesChanged`, `TaxRatesChanged`.
- Exceptions (stable codes): `pricing.price_list_not_found`,
  `pricing.price_list_archived`, `pricing.price_not_found`,
  `pricing.price_window_overlap`, `pricing.duplicate_code`,
  `pricing.currency_mismatch`, `pricing.tax_class_not_found`,
  `pricing.tax_zone_not_found`, `pricing.tax_rate_not_found`,
  `pricing.tax_rate_overlap`, `pricing.tax_zone_member_taken`,
  `pricing.invalid_tax_result`, `pricing.invalid_*` (command shapes).

## Promotions: `Promotions`

`promotions()` (the `PromotionDefinitions` contract, module `Definitions`: create,
update, pause, resume, archive, assign and unassign channels, find, list, `explain`)
and `coupons()` (the `Coupons` contract: create, generate, disable, enable,
`setLimit`, find, list). Implements the `PromotionUsage` port, adds the quote
stages `promotions.catalog`, `promotions.cart` and `promotions.delivery` and
declares the `promotion_condition`, `promotion_effect` and `promotion_activator`
SPIs. A code entered into a cart is only a presented code in `QuoteInput`;
limits are consumed when the order is placed (D13, D27).

```php
$promo = Promotions::promotions()->create(new CreatePromotion(
    code: 'autumn-10', name: 'Autumn −10%', stage: PromotionStage::Cart,
    activator: 'coupon', channels: [$channel->id], stacking: Stacking::Stackable, priority: 100,
    conditions: [new RuleSpec('cart.subtotal_at_least', ['amount' => Money::of('3000.00', 'RUB')])],
    effects:    [new RuleSpec('order.percent_off', ['rateBp' => 1000])],
    limits: new UsageLimits(total: 1000, perCustomer: 1), budget: Money::of('500000.00', 'RUB'),
    startsAt: $from, endsAt: $to,
));                                                    // currency is derived from the budget and rules: RUB
Promotions::promotions()->update(new UpdatePromotion($promo->id, limits: new UsageLimits(total: 2000)));
Promotions::promotions()->pause(new PausePromotion($promo->id));
Promotions::coupons()->generate(new GenerateCoupons(promotion: $promo->id, count: 500, pattern: 'AUT-####-####'));
Promotions::coupons()->create(new CreateCoupon(promotion: $promo->id, code: 'AUTUMN', usageLimit: 1000));
$why = Promotions::promotions()->explain(new ExplainPromotions($quoteInput));   // PromotionExplanation

// the core port, called by sales inside the PlaceOrder transaction (P7):
app(PromotionUsage::class)->consume(new ConsumePromotionUsage('order:'.$orderId, $ctx->customer, $quote->appliedPromotions()));
app(PromotionUsage::class)->restore(new RestorePromotionUsage('order:'.$orderId, RestoreReason::Cancelled));
```

- A promotion has a `code` (`[a-z0-9][a-z0-9_.-]{0,63}`, unique), a `name` (the
  explanation text of its adjustments), an immutable `stage` and `activator`
  (`automatic`, `coupon` or a plugin's key), `stacking`, `stopFurther`,
  `priority` (higher first), an optional immutable `currency`, a `status`
  (`active`, `paused`, `archived`; archived is terminal), a window
  `[startsAt, endsAt)`, at least one channel, 0–20 conditions, 1–10 effects,
  `UsageLimits(total, perCustomer)`, a `budget` and a `revision` that grows with
  every change of rules, limits or window. A rule must allow the promotion's
  stage (`promotions.rule_not_allowed_in_stage`); a rule or activator whose
  plugin is gone makes the promotion `unsupported` and not applied
  (fail-closed), reported by `commerz:promotions:reconcile`.
- Order inside a stage is `priority DESC, code ASC`; stackable promotions apply
  sequentially to the reduced price (D15, D26). An exclusive promotion applies
  only if it is first in its stage and stops the rest; `stopFurther` stops the
  rest after an applied promotion.
- Coupon codes are upper-case `[A-Z0-9][A-Z0-9_-]{0,63}`; every writer and
  lookup normalizes through `PromotionCodes::couponCode` (trim + upper case,
  PRM-03). `generate` fills a pattern (`#` = a character of
  `commerz-promotions.coupons.alphabet`) with random codes and refuses a pattern
  with too few `#` or whose code space is smaller than 100 times the count
  (`promotions.pattern_too_small`).
  Code creation is serialized across promotions until the outer transaction
  ends to avoid collisions deadlocking across generation rounds; hosts that
  also mutate definitions in that transaction call code creation first.
- `explain` re-runs the real pipeline and returns a `PromotionExplanation`:
  the `QuoteResult` (or the `failure` code and no quote), a `PromotionVerdict`
  per candidate in stage order (`Applied` with the discount, `Rejected` or
  `Skipped` with a `reason`) and `CodeRejection`s of presented codes. The quote
  itself carries rejections only for presented codes. Reasons: `no_eligible_lines`
  and the rule's own, `usage_limit_reached`, `per_customer_limit_reached`,
  `customer_required`, `no_delivery`, `unsupported_rule`, `not_combinable`,
  `stopped_by_promotion`; code reasons: `unknown_code`, `coupon_disabled`,
  `coupon_exhausted`, `promotion_not_active`, `duplicate_code`,
  `duplicate_promotion`, `too_many_codes`.
- `QuoteResult::appliedPromotions()` (D25) is the only source of the `applied`
  list of `ConsumePromotionUsage`; `consume` is idempotent per `orderRef` and
  payload (`IdempotencyConflict` for another payload), `restore` is idempotent
  and follows `promotions.restore_on` (default `cancelled`, `expired`).
- Views: `PromotionView`, `CouponView`, `CouponBatchView`; the promotion view
  carries a `PromotionUsageSummary` (orders not restored and the discount given
  per currency), counted from the usage log, not from the limit counters.
- Events after commit (`CommittedEvents`): `PromotionChanged(PromotionId)`,
  `CouponsGenerated(PromotionId, CouponBatchId, int count)`. No outbox message in
  0.1: there is no cross-package consumer.
- Exceptions (stable codes, subclasses of the core errors): `promotions.promotion_not_found`,
  `promotions.promotion_archived`, `promotions.coupon_not_found`,
  `promotions.not_a_coupon_promotion`, `promotions.code_space_exhausted`,
  `promotions.pattern_too_small`, `promotions.duplicate_code`,
  `promotions.promotion_unavailable` (reason `promotion_not_found`,
  `promotion_not_active`, `coupon_not_found`, `coupon_disabled`),
  `promotions.invalid_rule_config`, `promotions.invalid_rule_result`,
  `promotions.unknown_rule`, `promotions.unknown_activator`,
  `promotions.rule_not_allowed_in_stage`, `promotions.currency_mismatch`,
  `promotions.invalid_*` (command shapes); from the core port
  `promotions.usage_limit_reached` (`UsageLimitReached`, limit `total`,
  `per_customer`, `coupon`, `budget`) and `common.idempotency_conflict`.
- Console: `commerz:promotions:reconcile` (read-only, exit 1 on a discrepancy);
  see *Promotions lock order* in `invariants.md`.

## Shipping: `Shipping`

`zones()`, `methods()`, `options()` (`ShippingOptions`: `list` and `explain`;
`list` is the same answer as the `DeliveryOptions` port), `bookings()` (journal
reads; booking itself is the `ShipmentBooking` port, always outside a database
transaction).

```php
$ru  = Shipping::zones()->create(new CreateZone(code: 'ru', name: 'Россия', members: [ZoneMember::country('RU')]));
$msk = Shipping::zones()->create(new CreateZone(code: 'msk', name: 'Москва', members: [ZoneMember::region('RU-MOW')]));
Shipping::methods()->create(new CreateMethod(
    code: 'courier', name: new LocalizedText(['ru' => 'Курьер', 'en' => 'Courier']), carrier: 'manual',
    channels: [$channel->id],
    rates: [
        new ZoneRate($ru->id, Currency::of('RUB'), new RateSpec('weight_table', ['steps' => [
            ['up_to_grams' => 1000, 'amount' => Money::of('300.00', 'RUB')],
            ['up_to_grams' => 5000, 'amount' => Money::of('500.00', 'RUB')],
        ]]), etaDays: 3),
        new ZoneRate($msk->id, Currency::of('RUB'), new RateSpec('flat', ['amount' => Money::of('250.00', 'RUB')]),
            freeAbove: Money::of('5000.00', 'RUB'), etaDays: 1),
    ],
));
$options = Shipping::options()->list(new DeliveryRequest($ctx, destination: $address, parcel: $parcel));
$why     = Shipping::options()->explain(new DeliveryRequest($ctx, $address, $parcel));   // a verdict per method
// A quote with `DeliverySelection('courier', $address, $parcel)` adds a `DeliveryCharge` and `totals.deliveryTotal`.
// Outside a transaction (SHP-04), safe to repeat (SHP-03):
app(ShipmentBooking::class)->book(new BookShipment('fulfillment:'.$id, 'manual', 'courier', $address, $parcel));
$booking = Shipping::bookings()->find('fulfillment:'.$id);   // BookingView; list(ListBookings) pages by (created_at, id)
```

- A zone has members of three kinds (country, ISO 3166-2 region, postal prefix
  within a country); zones overlap and the most specific matching active zone
  with a tariff wins: postal prefix > region > country, a tie by `zone.code`
  (D28, SHP-06). A tariff is one row per (method, zone, currency): a
  `delivery_rate` rule, `freeAbove`, `taxIncluded`, `etaDays`. The currency is
  the context's; there is no conversion.
- `freeAbove` compares the goods after discounts, inclusive; the quote stage
  recomputes that base, so an option and the quote give one price (SHP-01).
  A selected method that cannot deliver fails the quote with
  `QuoteFailed(method_unavailable)` (SHP-02).
- Differences from the historical `api-blueprint#shipping` (each logged as a
  deviation in P6.7): `CreateZone(members: list<ZoneMember>)` instead of
  `match: ZoneMatch::regions(...)`; `rates: list<ZoneRate>` per (zone,
  currency) instead of `zones:` plus one `rate:`; the rule is
  `Commerz\Shipping\Rates\Data\RateSpec`, not the promotions `RuleSpec`;
  `weight_table` steps are `{up_to_grams, amount: Money}`; `free_over` became
  the tariff field `freeAbove` (D28); `options()` is `ShippingOptions` with
  `list()` and `explain()`; `bookings()` was added.
- Booking states: `pending → booked | rejected | unknown`. A timeout is
  `unknown`, never "failed, create again": the retry calls `Carrier::find` by
  the same reference and books anew only when it finds nothing (D29).
- The booking journal governs repeats (D30): `booked` returns the stored result
  even after the method is archived, its carrier is changed or its plugin is
  removed. `unknown` or expired `pending` recovers with the original carrier
  and payload. An unavailable original carrier leaves the outcome `unknown`
  for a later retry. New operations and fresh attempts after final rejection
  validate the current method. Invalid carrier results, including metadata
  containing NUL that PostgreSQL cannot store, become `unknown`.
- Errors (stable codes): `shipping.zone_not_found`, `zone_archived`,
  `method_not_found`, `method_archived`, `duplicate_code`,
  `duplicate_zone_member`, `too_many_zone_members`, `duplicate_rate`,
  `too_many_rates`, `unknown_rate`, `invalid_rate_config`,
  `invalid_rate_result`, `unknown_carrier`, `carrier_mismatch`,
  `carrier_rejected`, `invalid_carrier_result`, `currency_mismatch`,
  `delivery_unavailable`, `booking_not_found`, `booking_in_progress`,
  `booking_outcome_unknown`, `booking_inside_transaction`; shapes
  `shipping.invalid_*`; from the core `common.idempotency_conflict`,
  `delivery.invalid_*`, `quotes.failed` (reason `method_unavailable`).
- Explain verdicts: `available`, `method_not_found`, `method_disabled`,
  `not_in_channel`, `unsupported_carrier`, `no_zone`, `no_rate_for_currency`,
  `unsupported_rate`, `invalid_rate_result`, or a rule's own reason
  (`weight_out_of_range`).
- Events after commit: `ZoneChanged`, `MethodChanged`,
  `ShipmentBooked(reference, carrier, tracking)`. No outbox in 0.1: no package
  consumes them.

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
