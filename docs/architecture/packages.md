# Packages and dependencies

Commerz is a monorepo of Composer packages under `packages/<dir>/`, published
later as split mirrors (like Chatom). Vendor `axiomasoft`, root namespace
`Commerz\`. This document fixes which package owns which tables and how
packages may depend on each other.

## Package table

| Directory | Composer | Namespace | Owns (table nouns) | Implements core ports | Depends on |
|---|---|---|---|---|---|
| `packages/core` | `axiomasoft/commerz` | `Commerz\` | `store`, `channel`, `schema`, `operation`, `outbox` | defaults: unlimited stock, snapshot price, guest customers, null promotion usage | `illuminate/*` ^13, `brick/math` |
| `packages/catalog` | `axiomasoft/commerz-catalog` | `Commerz\Catalog\` | `product`, `variant`, `option`, `attribute`, `category`, `listing` | `SellableProvider` (key `variant`) | core |
| `packages/inventory` | `axiomasoft/commerz-inventory` | `Commerz\Inventory\` | `stock` (`cmz_stock_locations`, `_items`, `_item_links`, `_levels`, `_movements`, `_reservations`, `_transfers`, `_sources`) | `StockAvailability`, `StockReservations` | core |
| `packages/pricing` | `axiomasoft/commerz-pricing` | `Commerz\Pricing\` | `price`, `tax` | `PriceResolver`, quote stage `tax` | core |
| `packages/promotions` | `axiomasoft/commerz-promotions` | `Commerz\Promotions\` | `promotion`, `coupon` | `PromotionUsage`, quote stages `promotions.*` | core |
| `packages/shipping` | `axiomasoft/commerz-shipping` | `Commerz\Shipping\` | `shipping` (`cmz_shipping_zones`, `_methods`, `_rates`) | `DeliveryOptions`, `ShipmentBooking`, quote stage `delivery` | core |
| `packages/sales` | `axiomasoft/commerz-sales` | `Commerz\Sales\` | `cart`, `checkout`, `order`, `payment`, `fulfillment`, `return`, `refund` | none (consumer of ports) | core |
| `packages/full` | `axiomasoft/commerz-full` | — | — | — | all of the above (metapackage) |
| `packages/testkit` | `axiomasoft/commerz-testkit` | `Commerz\Testkit\` | — | in-memory fakes of all ports, plugin contract tests, concurrency harness | core (dev use) |

Stores and channels are a core module, not a package (D4): every package needs
the channel, so a separate package would become a second core. Coupons are a
module of promotions built on the public SPI (D5). Delivery quoting and carrier
booking belong to shipping; order fulfillment bookkeeping belongs to sales
(D14).

## Core modules

Package `packages/core`, namespace `Commerz\`. Each module exposes only the
public categories it needs (see [Code layout](#code-layout)).

| Module | Public types (abbreviated) |
|---|---|
| `Attributes` | `Api`, `Spi`, `SpiKind` |
| `Money` | `Money`, `Currency`, `RoundingMode`, `Rate` (basis points), `MoneyAllocator` |
| `Measures` | `Quantity` (int + unit), `Measure` (decimal string + unit, for attributes), `LocalizedText` |
| `Identity` | `EntityId` (abstract, UUIDv7), `Uuid7` (generator, test seam), `SellableRef`, `CustomerRef`, `ActorRef`, `IdempotencyKey` |
| `Context` | `CommerceContext`, `Contracts\ContextResolver` (SPI for hosts) |
| `Errors` | `CommerzException`, `ErrorCategory`, `InvalidCommand`, `NotFound`, `IdempotencyConflict`, `InvalidConfiguration`, `UnsupportedContribution` |
| `Configuration` | `CommerzConfig`, `StockMode`, `CustomersMode` |
| `Schema` | `TableNames`, `CommerzMigration`, `ConstraintNames`, `Contracts\SchemaOwnership` |
| `Operations` | `Contracts\Transactions`, `Contracts\OperationReceipts` |
| `Outbox` | `Contracts\Outbox`, `IntegrationEvent`, relay command |
| `Plugins` | `Contracts\CommerzPlugin`, `PluginManifest`, `PluginRegistrar`, `ContributionKind`, `Contracts\PluginInspector`, `Contracts\Contributions` |
| `Stores` | `Contracts\Stores`, `Contracts\Channels`, `StoreId`, `ChannelId`, commands, views, events |
| `Pagination` | `Page<T>`, `Cursor` |
| `Ports\Sellables` | `Sellables`, `SellableProvider`, `SellableSnapshot`, `SellableKind`, `SellableUnavailable` |
| `Ports\Stock` | `StockAvailability`, `StockReservations`, DTOs, `InsufficientStock` |
| `Ports\Prices` | `PriceResolver`, `ResolvePrice`, `ResolvedPrice` |
| `Ports\Promotions` | `PromotionUsage`, `ConsumePromotionUsage`, `RestorePromotionUsage`, `UsageLimitReached` |
| `Ports\Delivery` | `DeliveryOptions`, `ShipmentBooking`, `DeliveryRequest`, `DeliveryOption`, `Address`, `Parcel` |
| `Ports\Customers` | `CustomerDirectory`, `CustomerProfile` |
| `Quotes` | `QuotePipeline`, `QuoteStage`, `QuoteAnchor`, `QuoteInput`, `QuoteState`, `QuoteResult`, `Adjustment`, `TaxLine`, `QuoteTotals` |
| root | `Commerz` (root API), `Facades\Commerz`, `CommerzServiceProvider` |

The core knows nothing about feature packages: no class names, no contribution
kinds they declare.

## Dependency rules

Enforced by architecture tests in `tests/Arch`.

1. A feature package depends only on `axiomasoft/commerz`. Never on another
   feature package. Never core → feature.
2. Cross-package interaction happens only through core ports
   (`Commerz\Ports\*`) and events. Importing another package's `Internal`
   namespace is forbidden.
3. A package writes only to tables with its own nouns. There are no
   cross-package foreign keys; references are typed IDs or ref strings
   (`sellable_ref`, `customer_ref`, `order_ref`).
4. Inside a package, normal foreign keys are used.
5. The link "sellable ↔ stock item" belongs to inventory
   (`cmz_stock_item_links`: `sellable_ref` → `stock_item_id`,
   `quantity_per_unit`) because the stock invariant needs it.
6. All packages of one installation run on one connection
   (`commerz.connection`); order placement is one local transaction and port
   mutations join the ambient transaction through a savepoint (D20).

## Code layout

Canon from CoreX (STR-002/005/006) and Chatom:

```text
packages/inventory/
  composer.json  config/commerz-inventory.php  database/migrations/
  src/
    Inventory.php                     # root API class of the package, #[Api]
    InventoryServiceProvider.php
    Facades/Inventory.php
    Reservations/                     # feature module = one use-case cluster
      Contracts/StockReservations.php # #[Api] or #[Spi]
      Data/ReserveStock.php, ReservationView.php, ReservationId.php
      Enums/ReservationState.php
      Events/StockReserved.php
      Exceptions/InsufficientStock.php
      Internal/ReserveStockHandler.php, ReservationRepository.php, Models/Reservation.php
  tests/Unit  tests/Feature
```

- Public categories of a module are only `Contracts`, `Data`, `Enums`,
  `Events`, `Exceptions`. Everything else lives in `Internal/` and is closed to
  other modules and packages.
- Eloquent models are allowed only in `Internal/Models` as a storage detail;
  they never appear in public signatures (views are DTOs).
- Class suffixes: `Repository`, `Resolver`, `Registry`, `Handler` (one
  operation), `Policy`, `Codec`, `Compiler`, `Provider`, `Gateway`. No
  `Service` or `Manager`.
- One config file per package (`config/commerz.php`,
  `config/commerz-<pkg>.php`), read into a typed `*Config` at boot;
  configuration errors throw with the key path.

## Future packages

Not part of release 0.1; each gets its own plan:

- Transports: `commerz-http` (REST + problem+json), `commerz-filament` (admin).
- Ecosystem bridges: `commerz-corex` (Flex/CoreX module, custom fields,
  tenancy), `commerz-azguard` (permissions), `commerz-vaulter` (media),
  `commerz-chatom` (order chat).
- Adapters: payment (`commerz-payment-yookassa`, `-stripe`), carriers
  (`commerz-shipping-cdek`), search engines.
- Domains: `commerz-giftcards`, `commerz-loyalty`, extended WMS.
