# Introduction

Commerz is a **headless commerce engine for Laravel**. It owns the domain of
trade — stores and channels, catalog, stock, prices and taxes, promotions and
coupons, delivery, and the path from cart through order, payment, fulfillment
and return — and nothing else. The host application owns its transport,
authentication, storefront and data.

Commerz is built to be embedded. The same packages serve an online store and a
CRM:

| Consumer | Installs | Host provides |
|---|---|---|
| Online store | `axiomasoft/commerz-full` | storefront, authentication, payment adapter |
| CRM | `axiomasoft/commerz` + `axiomasoft/commerz-sales` (+ pricing) | its own sellable entities, customers |
| Stock sync | `axiomasoft/commerz` + `axiomasoft/commerz-inventory` | item source, import |
| PIM / showcase | `axiomasoft/commerz` + `axiomasoft/commerz-catalog` | UI, media |

## Packages

| Package | Composer | Owns |
|---|---|---|
| [core](../packages/core/) | `axiomasoft/commerz` | money, context, ports, plugins, stores and channels, operations, outbox |
| [catalog](../packages/catalog/) | `axiomasoft/commerz-catalog` | products, variants, options, attributes, categories, listings |
| [inventory](../packages/inventory/) | `axiomasoft/commerz-inventory` | locations, stock items and levels, reservations, transfers |
| [pricing](../packages/pricing/) | `axiomasoft/commerz-pricing` | price lists, prices, tax classes, zones and rates |
| [promotions](../packages/promotions/) | `axiomasoft/commerz-promotions` | promotions, rules, coupons, usage limits |
| [shipping](../packages/shipping/) | `axiomasoft/commerz-shipping` | zones, methods, rates, delivery options, carrier bookings |
| [sales](../packages/sales/) | `axiomasoft/commerz-sales` | carts, checkout, orders, payments, fulfillment, returns, refunds |
| [testkit](../packages/testkit/) | `axiomasoft/commerz-testkit` | test case, port fakes, plugin contract tests, concurrency helper |
| [full](../packages/full/) | `axiomasoft/commerz-full` | metapackage requiring the core and the six feature packages |

Feature packages depend only on the core and talk to each other through core
ports and events, so any subset installs on its own.

## Stack

PHP 8.4–8.6, Laravel ^13, PostgreSQL ≥ 16. SQLite is not supported: invariants
rely on `EXCLUDE` constraints, partial unique indexes, `jsonb` and row locks.

## What Commerz is not

Not a storefront or admin panel, a payment provider or carrier (it defines the
SPI, you write the adapter), a WMS or ERP, a marketplace settlement engine, a
jurisdiction tax engine (it has a rates-based calculator and a port for
yours), a subscription engine, or a gift-card and loyalty system. See
[Limitations](#limitations).

## Limitations

- Multi-tenancy is a host decision: one connection or database per tenant.
  There is no tenant column.
- Version 0.1 is a pre-release line: public types are marked, but the
  [SemVer policy](../reference/semver-policy) only starts to bind with 1.0.
- Money is held in minor units with an explicit currency; there is no
  currency conversion.
- Plugins are trusted in-process code. No sandbox is promised.

## Where next

- [Installation](./installation) — require the packages, publish, migrate, verify.
- [Quick start: shop](./quick-start-shop) — a store from catalog to refund.
- [Embedding in a CRM](./crm-embedding) — the subset install.
- [Concepts](../concepts/stores-and-channels) — the ideas behind the API.
- [Architecture](../architecture/README) — the binding design documents.
