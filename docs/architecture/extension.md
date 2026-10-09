# Extension model

Commerz is extended through exactly five mechanisms. Anything else (model
swapping, generic before/after hooks, executable configuration, subscriptions
stored in the database) is out by design.

## Mechanisms

| Mechanism | Changes | Example |
|---|---|---|
| Data | assortment, attributes, prices, promotions, warehouses, delivery zones | a new attribute "fabric density" without a release |
| Typed configuration | enum policies | `ReservationTiming::OnOrderPlaced` |
| Plugins (contribution kinds) | new behavior under a stable key | promotion effect `acme.loyalty_x2`, attribute type `acme.color_swatch`, gateway `yookassa` |
| Port implementation | replaces a whole module | `StockAvailability` → external stock adapter; a CRM's `SellableProvider` |
| Events + outbox | reactions to facts | `OrderPlaced` → e-mail, CRM, Chatom |

## Plugins

A plugin is a `CommerzPlugin`:

```php
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface CommerzPlugin
{
    public function manifest(): PluginManifest;                    // id 'vendor.name' [a-z0-9.-], version, requires, optional
    public function register(PluginRegistrar $registrar): void;   // declarations only, no IO
}
```

- `PluginRegistrar` (`#[Api]`): `declareKind(ContributionKind)`,
  `contribute(string $kind, object|string $contribution)` (the key comes from
  the contribution's `key()`), sugar `sellableProvider()` and `quoteStage()`.
- Commerz packages register their built-in contributions through the same API
  (dogfooding): `commerz.core`, `commerz.catalog`, … plus host plugins from
  `config('commerz.plugins')`.
- `PluginGraphCompiler` sorts plugins topologically by `requires`. Duplicate
  plugin id or contribution key, unknown kind, wrong interface, unknown
  required plugin or a cycle → `InvalidConfiguration` at boot.
- The compiled registry is frozen (`Contributions`, `#[Api]`:
  `get(kind, key, interface)`, `has()`, `keys()`). Compilation is lazy on first
  access and immutable for the rest of the process; it survives
  `config:cache` and long-running workers. No request state in singletons.
- Data stores the **stable contribution key** plus `config_version`, never a
  class name. A missing plugin makes the value or rule `unsupported`
  (fail-closed): the promotion does not apply, the attribute reads as a raw
  read-only value, `commerz:doctor` reports it.
- Plugins are trusted in-process code; no sandbox is promised.

## Contribution kinds

The set is closed. A kind is declared by the package that owns its SPI via
`PluginRegistrar::declareKind()`; the core does not know kinds of feature
packages.

| Kind | SPI owner | Built-in keys |
|---|---|---|
| `sellable_provider` | core | `variant` (catalog) |
| `quote_stage` | core | `base`, `totals` (core); `promotions.catalog`, `promotions.cart`, `promotions.delivery` (promotions); `delivery` (shipping); `tax` (pricing) |
| `outbox_consumer` | core | — |
| `attribute_type` | catalog (`Commerz\Catalog\Attributes\Contracts\AttributeType`) | `text`, `localized_text`, `integer`, `decimal`, `boolean`, `date`, `enum`, `multi_enum`, `measure`, `reference` |
| `product_kind` | catalog (`Commerz\Catalog\ProductTypes\Contracts\ProductKind`: `key()`, `sellableKind()`, `requiresShipping()`) | `physical`, `digital`, `service` |
| `allocation_strategy` | inventory (`Commerz\Inventory\Allocation\Contracts\AllocationStrategy`: `key()`, `allocate()`, `capacity()`; pure, no I/O) | `priority` (sources in priority order, a kit unit from one location), `single_location` (all needs of a request from one location). The plan is validated against the locked levels (`InvalidAllocationPlan`); selected by `commerz-inventory.allocation_strategy` |
| `tax_calculator` | pricing (`Commerz\Pricing\Calculation\Contracts\TaxCalculator`: `key()`, `calculate(TaxRequest): TaxResult`; no writes, time from the context; the result is validated by `TaxResultValidator`) | `rates` (class × zone × dated rates, several non-compound codes; included tax carved out by `Money::includedPart`, split across codes with `MoneyAllocator`). Selected by `commerz-pricing.tax.calculator` |
| `promotion_condition` | promotions (`Commerz\Promotions\Rules\Contracts\PromotionCondition`: `key()`, `version()`, `stages()`, `role()`, `normalize()`, `upgrade()`, pure `evaluate()`) | `cart.subtotal_at_least`, `cart.quantity_at_least`, `line.sellable_in`, `line.category_in`, `customer.in_group`. No `channel.in`: a promotion's channels are its assignment (D26) |
| `promotion_effect` | promotions (`PromotionEffect`: `key()`, `version()`, `stages()`, `normalize()`, `upgrade()`, pure `apply(EffectInput): list<Discount>`) | `line.percent_off`, `line.fixed_off`, `order.percent_off`, `order.fixed_off`, `delivery.percent_off`, `buy_x_get_y` |
| `promotion_activator` | promotions (`PromotionActivator`: `key()`, `activate(ActivationRequest): ActivationResult`; may read, never writes) | `automatic`, `coupon` (the coupon module plugs in through this same SPI, D5) |
| `delivery_rate` | shipping (`Commerz\Shipping\Rates\Contracts\DeliveryRate`: `key()`, `version()`, `normalize()`, `upgrade()`, pure `price()` returning `RatePrice::of(Money)` or `RatePrice::unavailable(reason)`; no writes, no network, time from the context) | `flat`, `weight_table` (steps `{up_to_grams, amount}`, inclusive, above the last step unavailable). The free-delivery threshold is the tariff field `free_above` applied by the engine to any rule, not a rule key (D28) |
| `carrier` | shipping (`Commerz\Shipping\Carriers\Contracts\Carrier`: `key()`, `book(CarrierBooking)`, `find(CarrierBooking): ?BookedShipment`; called only by `ShipmentBooking`, outside transactions, D29) | `manual` (no network; tracking from `meta['tracking']`, else the reference) |
| `payment_gateway` | sales | `manual` (offline / invoice) |
| `order_number_generator` | sales | `sequence` |

An `attribute_type` plugin requires `commerz.catalog` (it declares the kind),
so a package contributing a type lists the catalog plugin among its
dependencies. An attribute whose type is not installed stays readable as a
stored value, unprojected and read-only.

A rule contribution (condition, effect, rate) decodes and upgrades its own
configuration: `configVersion()`, `decodeConfig(array $raw, int $version)`,
and a pure `apply()`.

### Promotion rules and stages

Promotions run in three quote stages, `promotions.catalog`, `promotions.cart`
and `promotions.delivery`, each `At` its anchor and owned by the promotions
plugin (`commerz.promotions`). A promotion names its stage explicitly and
immutably; a condition or effect declares the stages it may be used in
(`stages()`) and `CreatePromotion` / `UpdatePromotion` refuse a rule outside
them (`promotions.rule_not_allowed_in_stage`). Host stages go `Before` / `After`
those anchors like any other (D26).

- **Conditions** run in two passes. A `LineFilter` (`line.sellable_in`,
  `line.category_in`) narrows the lines the effects act on; a `Qualifier`
  (`cart.subtotal_at_least`, `cart.quantity_at_least`, `customer.in_group`) says
  yes or no for the whole quote. Filters run
  first in declared order, then qualifiers. A condition returns a
  `ConditionOutcome`: `pass` (a filter with its eligible lines) or `fail` with a
  reason, and sees a read-only `ConditionInput`.
- **Effects** return `list<Discount>` (line, order or delivery target, a
  positive `Money` in the quote currency). The engine does not trust them:
  `DiscountGuard` checks the line, the currency and the remaining price,
  clamps at zero, and a promotion applies whole or not at all (PRM-05).
  A line discount is also capped by the remaining items total after any
  declared order discounts, whose total is preserved when shares are allocated
  over the final line nets at settlement (D23).
- **Activators** decide which promotions are switched on for a quote:
  `automatic` all of them, `coupon` those whose code is presented. They return
  an `ActivationResult` (activations plus `CodeRejection`s); the engine knows
  neither word, so a plugin can add `acme.referral` the same way.
  A third-party code uses `Activation($promotion, presentedCode: $code)`:
  `presentedCode` is matched against input codes and carries quote rejections;
  `couponCode` is reserved for an owned package coupon counted at placement.
  Without a package coupon, `PromotionUsage` still enforces promotion and
  customer limits and budget; spending a plugin's own resource is its concern.
- **Order and stacking** within a stage: `priority DESC, code ASC`; `Stackable`
  promotions apply one after another to the reduced price, `Exclusive` only as
  the first of its stage and then stops the rest; `stopFurther` stops the rest
  after an applied promotion.
- **Storage and trust.** Rules are stored as key + `config_version` + canonical
  JSONB parameters. Loading validates stage and parameters against the
  promotion's currency after any upgrade, including at the current version.
  A missing rule, activator, unreadable version or invalid parameters makes the
  promotion `unsupported` (not applied, verdict `unsupported_rule`, listed by
  `commerz:promotions:reconcile`). Stages and rules are pure: no database
  writes, no network, time from `context.at`; the one spending point is
  `PromotionUsage::consume` at placement. All three stages share one session per
  `QuoteInput` (one load of active promotions, not three).
- A plugin that adds a rule or activator lists `commerz.promotions` in
  `requires` and registers it before the registry compiles (tests: in
  `defineEnvironment`).

## Quote pipeline

`QuotePipeline` (core) turns a `QuoteInput` (context, lines with sellable
snapshots, presented codes, optional delivery selection) into a `QuoteResult`.

- Fixed ordered anchors (`QuoteAnchor`):
  `Base → CatalogPromotions → CartPromotions → Delivery → DeliveryPromotions → Tax → Totals`
  (`base`, `promotions.catalog`, `promotions.cart`, `delivery`,
  `promotions.delivery`, `tax`, `totals`).
- A `QuoteStage` declares `key()`, `anchor()` and `position()` (`At`,
  `Before`, `After`). Only the anchor's owner takes `At`; plugins go
  `Before`/`After` an anchor.
- A stage is a pure function `QuoteState → QuoteState`: no database writes,
  same input gives the same output. An exception becomes `QuoteFailed`.
- `QuoteState` is immutable (`withLine()`, `withAdjustment()`,
  `withDelivery()`, `withTaxLine()`). Every adjustment carries a target (line,
  order, delivery), a negative amount, its `source` key, promotion and coupon,
  and an explanation; rejected promotions and codes are recorded with a reason.
- Core stages: `base` (prices via `PriceResolver::resolveMany`) and `totals`
  (sums, "total ≥ 0" check, `MoneyAllocator` spreads each order-level
  discount over lines by what is left of them after line discounts and
  earlier shares). An anchor without a contributing package is skipped. A
  money change after `totals` drops the totals and fails the quote.
- The result holds lines with allocated adjustments and taxes, totals
  (`subtotal`, `discountTotal`, `deliveryTotal`, `taxTotal`, `total`),
  rejections and a `fingerprint` (sha256 of the whole input, context time
  included, and the key and plugin version of every stage that ran).
- The `tax` stage (pricing, `At` the `Tax` anchor) builds one taxable item per
  line from the base **after all discounts** (`QuoteState::allocatedOrderAdjustments()`
  gives the same order-level shares `totals` settles) and one for delivery
  (`deliveryNet()`, when `tax.delivery_class` is set), asks the configured
  `tax_calculator` and adds one `TaxLine` per line and tax code, rounded per
  line (the sum can differ from tax carved out of the order total). A
  tax-exempt customer (`CustomerDirectory`) gets no tax lines. A missing rate
  for a class in the zone fails the quote (`QuoteFailed` stage `tax`, cause
  `pricing.tax_rate_not_found`).
- Stackable promotions within one stage apply sequentially to the already
  reduced price, ordered by `priority DESC, key ASC` (D15).

## Port defaults

Every port has a core default so a partial installation works:

| Port | Core default |
|---|---|
| `StockAvailability`, `StockReservations`, `StockReturns` | `UnlimitedStock` when `commerz.stock = unlimited`; `inventory` without the package → `InvalidConfiguration` on first use of the port |
| `PriceResolver` | `SnapshotPriceResolver` (sellable base price, otherwise `PriceNotFound`) |
| `PromotionUsage` | `NullPromotionUsage` (nothing applied) |
| `CustomerDirectory` | guests only |
| `DeliveryOptions`, `ShipmentBooking` | no options, booking unsupported; delivery anchors are skipped |

The testkit provides in-memory fakes of every port for package and host tests.
