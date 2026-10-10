# Changelog

All notable changes to the Commerz monorepo are documented in this file. All packages are released in lockstep with one version.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-10-10

First release of the Commerz family: a headless, transport-agnostic commerce domain for Laravel 13 (PHP 8.4, PostgreSQL ≥ 16) that embeds in both a CRM and an online store. While the major version is `0`, a minor release may change the public API; the surface is recorded per package in `resources/contracts/php-api.json` and published under [`docs/reference/api`](docs/reference/api/index.md). Types under `Internal` are not covered by SemVer.

### Added

- `axiomasoft/commerz` (core): `Money`, `Quantity` and `Currency` value objects; `CommerceContext`; stores and channels; the quote pipeline; the plugin registry and the `commerz:plugins` diagnostics; idempotent operations with receipts (`commerz:operations:prune`); the transactional outbox and its relay (`commerz:outbox:relay`); the stock, price, promotion, delivery and customer-directory ports with defaults that work alone (unlimited stock, base prices, no promotions, no delivery); `commerz:install` and `commerz:doctor`.
- `axiomasoft/commerz-catalog`: products, variants and option matrices, attributes, categories and per-channel listings; the `variant` sellable; plugin `commerz.catalog` with the `attribute_type` and `product_kind` kinds.
- `axiomasoft/commerz-inventory`: stock locations, items and levels, reservations and transfers with PostgreSQL-enforced invariants; the core stock ports; plugin `commerz.inventory` with the `allocation_strategy` kind; `commerz:inventory:reconcile` and `commerz:inventory:expire-reservations`.
- `axiomasoft/commerz-pricing`: price lists, prices with non-overlapping validity windows (`btree_gist` exclusion constraints), tax zones, classes and rates; `PriceResolver` and the `tax` quote stage; plugin `commerz.pricing` with the `tax_calculator` kind.
- `axiomasoft/commerz-promotions`: promotions, coupons, stacking and usage limits (total, per customer, budget) with a defined lock order; `PromotionUsage` and the three promotion quote stages; plugin `commerz.promotions` with the `promotion_condition`, `promotion_effect` and `promotion_activator` kinds; `commerz:promotions:reconcile`.
- `axiomasoft/commerz-shipping`: delivery zones, methods and tariffs, delivery options, carrier bookings with a durable journal and conflict recovery; `DeliveryOptions` and `ShipmentBooking`; plugin `commerz.shipping` with the `delivery_rate` and `carrier` kinds.
- `axiomasoft/commerz-sales`: carts, checkout and idempotent order placement, orders, payments with provider events, fulfillment, returns and refunds; works without catalog and inventory through a host `SellableProvider` (CRM); plugin `commerz.sales` with the `payment_gateway` and `order_number_generator` kinds; `commerz:sales:expire-orders`.
- `axiomasoft/commerz-testkit` (development dependency): Testbench test case, in-memory fakes of every core port, plugin contract tests and a multi-process concurrency helper.
- `axiomasoft/commerz-full`: metapackage that requires the core and the six feature packages.
- Public surface classified with `#[Api]` / `#[Spi]`, recorded in each package's `resources/contracts/php-api.json` and checked in CI (`composer api:check`).
- Architecture rules enforced by the `Arch` suite: feature packages depend only on the core, own their tables and import no other package's `Internal`.
- End-to-end scenarios on the real packages (online store and CRM), a cross-package concurrency suite on PostgreSQL with deadlock detection and a positive control, and a consumer install smoke from composer archives in a fresh Laravel 13 application (`scripts/test-consumer-install.sh`).
- Documentation site (VitePress) in [`docs/`](docs/index.md) with generated API reference.

### Known limitations

- PostgreSQL only (≥ 16, tested on 17); no MySQL or SQLite. PHP ^8.4 and Laravel ^13 only.
- No HTTP/REST API, admin panel or storefront UI; they are future `commerz-http` / `commerz-filament` packages.
- No bridges to the other ecosystem packages and no concrete payment or carrier adapters.
- No WMS (bins, lots, serial numbers), cost accounting, purchasing, marketplace settlement, subscriptions, gift cards or loyalty points.
- Not published to Packagist and not split into mirror repositories; install from the monorepo.

[0.1.0]: https://github.com/axiomasoft/commerz/releases/tag/v0.1.0
