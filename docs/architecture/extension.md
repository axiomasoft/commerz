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
| `attribute_type` | catalog | `text`, `localized_text`, `integer`, `decimal`, `boolean`, `date`, `enum`, `multi_enum`, `measure`, `reference` |
| `product_kind` | catalog | `physical`, `digital`, `service` |
| `allocation_strategy` | inventory | `priority`, `single_location` |
| `tax_calculator` | pricing | `rates` |
| `promotion_condition` | promotions | `cart.subtotal_at_least`, `cart.quantity_at_least`, `line.sellable_in`, `line.category_in`, `customer.in_group`, `channel.in` |
| `promotion_effect` | promotions | `line.percent_off`, `line.fixed_off`, `order.percent_off`, `order.fixed_off`, `delivery.percent_off`, `buy_x_get_y` |
| `promotion_activator` | promotions | `automatic`, `coupon` |
| `delivery_rate` | shipping | `flat`, `weight_table`, `free_over` |
| `carrier` | shipping | `manual` |
| `payment_gateway` | sales | `manual` (offline / invoice) |
| `order_number_generator` | sales | `sequence` |

A rule contribution (condition, effect, rate) decodes and upgrades its own
configuration: `configVersion()`, `decodeConfig(array $raw, int $version)`,
and a pure `apply()`.

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
- Stackable promotions within one stage apply sequentially to the already
  reduced price, ordered by `priority DESC, key ASC` (D15).

## Port defaults

Every port has a core default so a partial installation works:

| Port | Core default |
|---|---|
| `StockAvailability`, `StockReservations` | `UnlimitedStock` when `commerz.stock = unlimited`; `inventory` without the package → `InvalidConfiguration` on first use of the port |
| `PriceResolver` | `SnapshotPriceResolver` (sellable base price, otherwise `PriceNotFound`) |
| `PromotionUsage` | `NullPromotionUsage` (nothing applied) |
| `CustomerDirectory` | guests only |
| `DeliveryOptions`, `ShipmentBooking` | no options, booking unsupported; delivery anchors are skipped |

The testkit provides in-memory fakes of every port for package and host tests.
