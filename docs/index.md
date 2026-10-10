---
layout: home

hero:
  name: Commerz
  text: An embeddable commerce engine for Laravel
  tagline: Headless and transport-agnostic. Catalog, stock, prices and taxes, promotions, delivery, and cart → order → payment → fulfillment → return, as a family of Composer packages on PostgreSQL.
  actions:
    - theme: brand
      text: Get started
      link: /getting-started/introduction
    - theme: alt
      text: Architecture
      link: /architecture/README
    - theme: alt
      text: API reference
      link: /reference/api/

features:
  - title: Embeddable
    details: An online store installs everything; a CRM installs the core and sales, and sells its own entities through a SellableProvider. Nothing carries code it does not use.
  - title: Typed API
    details: Commands are final readonly data classes, every module is a contract, results are views. Public types carry #[Api] or #[Spi] and follow SemVer.
  - title: Invariants in PostgreSQL
    details: Stock never goes negative, a coupon is never over-spent, an order is placed once. Constraints, locks and a fixed lock order enforce it, and N-process tests prove it.
  - title: Extensible by plugins
    details: Five extension mechanisms and a closed set of contribution kinds — gateways, carriers, tax calculators, promotion rules, quote stages — keyed by stable names.
---
