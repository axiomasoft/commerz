# 04-counterexamples-boundaries

- Status: completed
- Method: searchDeep
- Started (UTC): 2026-10-08T14:04:43.825Z
- Finished (UTC): 2026-10-08T14:07:32.176Z
- Transport: local perplexity-web-mcp daemon client via shell

## Question

Act as a critical architecture reviewer researching a maximally flexible reusable PHP/Laravel commerce package. Ground comparisons in primary official sources and public code of Sylius, Lunar, Bagisto, Magento/Adobe Commerce, Shopify, and optionally Vendure/Saleor/Medusa. Challenge the idea of one ideal universal schema. Analyze B2B quotes, subscriptions, digital goods, services, configurable/bundle products, multi-warehouse transfers, multivendor marketplace, taxes, refunds and partial fulfillment as stress cases. Which concepts should be stable relational core, optional modules, external integrations or plugins? Explain bounded-context ownership, avoiding duplicated source of truth, order-line historical snapshots, tenant vs shop vs channel and account/user ownership. Present 3 plausible architecture profiles with explicit exclusions, migration paths, worst failure modes and smallest vertical slices that can falsify design assumptions. Include exact primary/source URLs, separate facts/inferences, and do not assert private SaaS database structure.

## Unverified Perplexity answer

> Это сырой текст DOM-извлечения Perplexity. Таблицы, формулы и некоторые заголовки могли потеряться; отсутствие строки не означает отсутствие раздела в исходном ответе. Формулировки «verified/fact» внутри ответа принадлежат Perplexity. Собственная проверка — в [verified-findings.md](verified-findings.md). Список sources, возвращённый клиентом, содержит по 10 элементов и не считается полным списком всех использованных страниц.

The most defensible design is a kernel of stable commercial records and invariants, surrounded by independently owned domain modules—not a universal product schema with enough nullable columns to represent every business.
For an embeddable Laravel package, I would start with a modular monolith: host-owned authentication, a replaceable catalog adapter, durable order snapshots, explicit pricing/tax results, and separate payment, inventory, and fulfillment boundaries. B2B negotiation, subscriptions, booking, digital entitlements, and marketplace settlement should add their own aggregates and migrations.
The critical distinction is this:
A common checkout interface is plausible. A common lifecycle, inventory model, or legal transaction model for every sellable thing is not.

## 1. Evidence boundaries and comparison

I distinguish:
- Fact: directly supported by official documentation or readable public code.
- Inference: architectural interpretation of that evidence.
- Proposal: my recommended design, not a claim about an existing platform.
This review uses Lunar’s versioned 1.x documentation, Bagisto’s official documentation, Adobe Commerce documentation, Shopify’s public API contracts, and accessible public repository files. Branch URLs are mutable, not commit-pinned audits. The readable Sylius order-component source below is older source; it demonstrates an architectural approach, not a guarantee about current Sylius 2.x internals. Some GitHub pages exposed file metadata without their bodies, so I do not treat them as line-by-line code verification.
Shopify observations concern public API semantics only. They say nothing about Shopify’s private database tables.

## What the five systems actually contribute


## Exact primary and source URLs

These are the concrete comparison anchors, not a claim that every listed source was fully readable:
- Sylius readable order-item code:https://github.com/Sylius/Order/blob/master/Model/OrderItem.php
- Lunar package architecture:https://docs.lunarphp.com/1.x/getting-started/overview/introduction
- Lunar orders, lines, addresses, transactions, and extension examples:https://docs.lunarphp.com/1.x/reference/orders
- Lunar public repository:
- Bagisto product-type development:https://devdocs.bagisto.com/product-type-development/getting-started.html
- Bagisto product semantics:https://docs.bagisto.com/product-types/products.html
- Bagisto order-item source location; body extraction was incomplete:https://github.com/bagisto/bagisto/blob/master/packages/Webkul/Sales/src/Models/OrderItem.php
- Magento public sales-schema source location; body extraction was incomplete:https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/Sales/etc/db_schema.xml
- Adobe inventory allocation and reservations:https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/basics/selection-reservations
- Adobe B2B negotiable quotes:https://experienceleague.adobe.com/en/docs/commerce-admin/b2b/quotes/quotes
- Shopify inventory contract:https://shopify.dev/docs/api/admin-graphql/latest/objects/InventoryLevel
- Shopify fulfillment-work contract:https://shopify.dev/docs/api/admin-graphql/latest/objects/FulfillmentOrder
- Shopify subscription/order synchronization:https://shopify.dev/docs/apps/build/purchase-options/subscriptions/fulfillments/sync-orders-subscriptions

## 2. Why one ideal schema fails

The problem is not insufficient attributes. It is incompatible invariants.
Consider “quantity available”:
- A shirt has physical units at locations.
- An appointment has capacity during a time interval.
- A rental requires an asset to remain available across overlapping intervals.
- A download may have unlimited sales but limited license keys.
- A subscription has future obligations whose availability may be assessed at each cycle.
- A bundle may consume several components rather than its own stock.
Bagisto explicitly distinguishes variant, bundle, downloadable, virtual, and booking behavior. Shopify’s prepaid-subscription documentation describes scheduled fulfillments and reservation timing rather than reserving all future deliveries immediately. These are materially different operational models.
Proposal: unify only what can carry a meaningful shared contract:
```
Sellable reference → evaluated commercial offer → accepted order-line snapshot → one or more fulfillment obligations
```
Do not make these equivalences:
```
sellable = physical SKU product type = bounded context order line = warehouse picking line refund = returned inventory subscription = order with a recurring flag seller = tenant
```
A “universal” schema often postpones these distinctions into JSON and callbacks. That does not remove complexity; it removes database constraints and makes ownership harder to audit.

## 3. Stable core, modules, integrations, and plugins

The following is a proposed allocation, not a description of any platform’s schema.

## Stable relational core

Keep the core small but commercially complete:
A cart can be a first-party module without becoming the universal prerequisite for order creation. Quotes, subscription renewals, imports, and administrative sales should be able to submit an accepted commercial proposal directly.
Money needs explicit currency and arithmetic rules. Quantities need units and declared precision. An integer count is a valid profile restriction, not a universal commerce assumption.

## Optional first-party modules

These own behavior and their own relational tables:
- Catalog: products, variants, options, attributes, publication.
- Pricing/promotions: price lists, customer eligibility, offer evaluation.
- Payments/refunds: payment attempts, authorizations, captures, refund attempts.
- Inventory: stock items, locations, reservations, allocations, movements.
- Fulfillment: obligations, work orders, shipments, execution records.
- B2B: organizations, memberships, approvals, negotiated quote revisions.
- Subscriptions: contracts, schedules, cycles, renewal attempts.
- Digital delivery: entitlements, grants, license allocation, revocation.
- Services/booking: resources, time windows, holds, appointments.
- Marketplace: vendors, offers, commissions, settlement, payout liabilities.
“Optional” does not mean unstructured. Once installed, a module must have enforceable invariants.

## External integrations

Use adapters for PSPs, tax engines, carriers, WMS/ERP systems, billing providers, identity providers, and payout platforms.
An external integration can be authoritative for a bounded responsibility. For example, a WMS may own physical stock and shipment execution. Your package then owns a synchronized availability projection and reconciliation—not a competing physical-stock master.

## Plugins

Plugins are appropriate for:
- Payment and shipping drivers.
- Tax calculators.
- Pricing and eligibility policies.
- Sellable resolvers.
- Fulfillment handlers.
- Administrative UI extensions.
A plugin that owns subscription schedules or marketplace settlement is really a domain module, even if distributed as a Composer plugin.
Do not let plugins arbitrarily mutate accepted totals, write another module’s balance tables, or execute irreversible network calls inside order-acceptance transactions.

## 4. Stress cases that expose the boundaries

All “required design” entries below are proposals.

## B2B negotiation is not checkout with a discount field

Fact: Adobe documents multi-step negotiation, buyer/seller authority, company accounts, and quotes that can remain open while underlying prices change.
Proposal: define the acceptance policy explicitly:
- Are negotiated prices guaranteed until expiry?
- Are tax and shipping recalculated?
- Does acceptance require fresh availability?
- Does approval reserve stock or merely authorize purchasing?
- What happens if the buyer’s authorization is revoked before acceptance?
Use quote_id + revision as an order origin. Preserve the accepted revision; do not keep reading a mutable quote as the order’s price source.

## Subscriptions separate future agreements from present transactions

Fact: Shopify advises changing the subscription contract for unbilled items and the order/fulfillment objects for billed items. Canceling an order does not itself cancel the contract. Prepaid subscriptions can create one order with multiple scheduled fulfillment orders.
Inference: neither “one order per subscription” nor “one order per cycle” is universal.
Proposal: model a contract, billing cycle, and delivery obligation separately. Choose an order-generation policy for the supported profile. Make cycle identity unique so scheduler retries cannot create duplicate charges or orders.

## Bundle lines need two different decompositions

Proposal: distinguish:
- Commercial decomposition: what the customer purchased and how price/tax/refund value is allocated.
- Operational decomposition: which components, locations, or resources satisfy that purchase.
These may not match. A fixed-price kit can have one visible line but several warehouse components. A mixed physical/digital bundle can require both a shipment and an entitlement grant.
Do not infer refund value from current component prices.

## Warehouse transfers require a real lifecycle

Fact: Adobe distinguishes aggregated saleable inventory from physical source inventory, and Shopify exposes states including incoming, committed, available, and on-hand.
Proposal: a transfer should support partial dispatch, partial receipt, damage, loss, and cancellation of the remainder. Destination availability increases on the declared receiving event—not merely because a transfer request exists.
Do not count in-transit stock as saleable at both ends.

## Refund, return, and restock are independent

Fact: Adobe credit memos expose a separate “Return to Stock” choice and, with Inventory Management, return inventory to the source that shipped it.
Proposal: represent:
```
credit allocation payment refund attempt/result return authorization return receipt and inspection inventory disposition digital/service entitlement disposition
```
A goodwill refund may have no returned item. A returned item may be damaged and non-saleable. A PSP timeout may leave the refund outcome unknown rather than failed.

## 5. Bounded-context ownership and source-of-truth rules

A bounded context is a semantic ownership boundary, not necessarily a service or database.
For a Laravel modular monolith, one database is reasonable. Sharing a transaction does not justify sharing write ownership.

## Copies are not automatically duplicate truth

Three legitimate copies have different meanings:
- Historical snapshot: intentionally records the past.
- Projection: derived, rebuildable, potentially stale.
- Provider observation: records an externally authoritative outcome.
The dangerous duplication is two independently writable representations of the same present fact.
Examples of prohibited ambiguity:
- Catalog and Inventory both directly maintain authoritative stock quantity.
- Orders and Payments both independently decide whether a capture succeeded.
- Subscription and Orders both mutate the same future delivery schedule.
- Marketplace and Payments independently calculate seller payable balances.
Each duplicated field needs an owner, update mechanism, freshness policy, and reconciliation path.

## Order acceptance and external effects

Proposal:
- Resolve sellables and evaluate a versioned offer.
- Validate scope, customer authority, and acceptance policy.
- Acquire required local holds/reservations.
- Write accepted snapshots and durable operation records.
- Commit an outbox entry in the same local transaction.
- Perform external effects with idempotent commands.
- Reconcile uncertain outcomes.
There is no local SQL transaction that atomically includes an external PSP or WMS. If availability is externally owned, the profile must choose between confirmed external holds, eventual acceptance with compensation, or rejecting checkout while confirmation is unavailable.

## 6. Historical order-line snapshots

Fact: Lunar documents line descriptions, identifiers, quantities, unit prices, discounts, tax breakdowns, and totals alongside optional purchasable references. Its shipping-line documentation explicitly describes snapshotting display and pricing information without a purchasable model.
Proposal: every accepted line should remain interpretable after catalog deletion, repricing, or plugin removal.
Snapshot at least:
- Description, SKU/external identifier, selected options.
- Quantity, measurement unit, and precision.
- Currency, unit amount, discounts, charges, taxes, final allocated totals.
- Seller/legal-party identity where relevant.
- Bundle/configuration selection.
- Relevant fulfillment specification or its immutable reference.
- Tax classification and calculation evidence.
- Origin: cart, accepted quote revision, subscription cycle, import.
- Extension payload type and schema version.
Keep queryable and constrained financial fields relational. Versioned JSON is reasonable for a heterogeneous configuration snapshot; it is not a substitute for refund allocations or reservations.
The live catalog reference is navigational, not required to render an invoice or process a historical refund.
Freeze the accepted commercial revision, not every field on the order. Operational records can evolve. Commercial amendments should have explicit revisions or adjustment documents.

## 7. Tenant, shop, channel, account, and user

These terms should not be interchangeable.

## Proposed scope model

- Tenant: isolation, authorization, retention, and operational boundary.
- Shop: merchant/store domain, with an explicit relationship to the selling legal party.
- Channel: route to market and contextual configuration—storefront, mobile app, POS, wholesale portal.
- Market: geographic/commercial targeting when needed; not automatically a channel.
- Seller/vendor: party responsible for an offer or sale.
- Location: operational place or resource pool.
A tenant may contain several shops; a shop may expose several channels. A marketplace tenant may contain many sellers without making each seller a tenant.
Channel restrictions must not stand in for tenant isolation. Scope checks must also cover queues, caches, external IDs, admin queries, and webhook routing.
Fact: platform meanings differ. Lunar records channel, customer, and authenticated user separately on orders. Adobe maps inventory stocks to sales channels that its documentation identifies as websites. Those conventions are useful evidence, not universal definitions.

## Account and user ownership

- The host Laravel application owns authentication and its User.
- Commerce owns a buyer account only if the host does not already own that business concept.
- B2B accounts have memberships and purchasing roles.
- Orders distinguish buyer, placing actor, approver, and recipient.
- Guest purchases need no permanent authenticated user.
Use a host identity adapter or explicit identity mapping. Do not force inheritance from a package-owned user model.
If the host CRM already owns organizations and memberships, integrate with it rather than creating another independently editable company directory.
Historical party snapshots still belong to Orders; current profile changes must not rewrite the purchase-time identity.

## 8. Three plausible architecture profiles


## Profile A — Embedded retail package

Scope: Laravel applications selling ordinary physical variants and digital items, with one merchant per order.
Structure:
- First-party catalog, cart, pricing, orders.
- Optional local inventory, payments, fulfillment, digital delivery.
- Host-owned users.
- Deliberately simple quantity and currency restrictions.
Explicit exclusions:
- Multivendor settlement.
- Negotiated procurement workflows.
- Recurring contract management.
- Rentals and appointment capacity.
- Manufacturing and advanced WMS behavior.
Migration path:
- Add dedicated quote/subscription/booking modules using origin references.
- Replace simple stock storage behind the inventory boundary.
- Introduce explicit fulfillment obligations before sophisticated routing.
- Use expand/backfill/validate/cutover migrations, not a one-time reinterpretation of historical line metadata.
Worst failure mode: “flexibility” becomes arbitrary Eloquent overrides and plugin hooks; every installation acquires a different checkout algorithm.
Smallest falsifying slice:
Sell three units, fulfill one, rename and delete the catalog item, then refund one unfulfilled unit and replay the payment webhook.
Fail the design if historical rendering requires the deleted product, duplicate effects occur, or cancellation/refund cannot identify the affected quantities and amounts.

## Profile B — Operational modular commerce

Scope: B2B purchasing and multi-location physical operations inside a modular monolith.
- Explicit inventory reservations and movement records.
- Allocation and fulfillment work separate from execution.
- Negotiated quote revisions and approval policy.
- Structured returns and refund allocations.
- Outbox/inbox and reconciliation jobs.
- Marketplace payouts and seller balance accounting.
- General-purpose ERP/accounting.
- Manufacturing planning.
- Universal booking/resource scheduling.
- Arbitrary cross-merchant baskets.
- Move from product-level stock to inventory-owned balances and reservations.
- Add warehouse transfers and fulfillment allocation.
- Substitute a WMS through a documented ownership cutover.
- Keep accepted order history unchanged when operational ownership changes.
Worst failure mode: inventory and order/payment coordination disagree after crashes; stock is stranded or released twice.
Two concurrent buyers compete for the last units while a partial transfer is received. Accept one quote, ship from two locations, cancel the remainder, and restart workers after each durable step.
Fail if quantity conservation breaks, duplicate commands ship twice, or a completed/canceled order leaves unexplained reservations.

## Profile C — Commerce orchestration over domain systems

Scope: heterogeneous sellables, recurring services, or marketplace workflows where specialist systems own substantial behavior.
- Small local commercial kernel.
- External or modular catalogs, availability, billing, booking, and delivery.
- Durable orchestration and explicit operation state.
- Marketplace seller allocation and settlement module if multivendor is included.
- Generic local inventory correctness for every domain.
- One atomic transaction across providers.
- A single product table owning all operational behavior.
- Guaranteed checkout availability while every dependency is unavailable.
- Treating marketplace commission as a coupon.
- Extract one context at a time behind stable commands and events.
- Establish external-ID mappings and shadow projections.
- Reconcile before switching write ownership.
- Make the old writer read-only at cutover; avoid indefinite dual writes.
Worst failure mode: payment succeeds but booking or fulfillment fails, then compensation also fails. If those states are compressed into order.status = failed, operational recovery becomes guesswork.
Checkout contains a download, a scheduled service, and a physical item from another seller. Payment succeeds, booking times out, one vendor rejects fulfillment, and callbacks arrive duplicated and out of order.
Fail if the system cannot identify each outstanding obligation, liability, refund amount, and recovery action independently.

## 9. Recommendation for the reusable Laravel package

I would implement Profile A with Profile B’s ownership discipline—not Profile C’s distributed complexity.
The smallest durable public surface should be commands and immutable result objects, for example:
```
ResolveSellable EvaluateOffer AcceptOrder ReserveAvailability CreateFulfillmentWork RecordFulfillment RequestRefund RecordProviderOutcome
```
Avoid making Eloquent models the only integration API. Model extensibility is useful, but callers should not need to reproduce invariants by assigning columns and calling save().
Prioritize these decisions before adding product types:
- Choose one authoritative writer for every quantity, amount, and lifecycle.
- Separate accepted commercial lines from fulfillment components.
- Define snapshot and amendment semantics.
- Define tenant isolation independently of channel configuration.
- Keep account ownership compatible with the host application.
- Make uncertain external outcomes recoverable.
- Require each optional module to pass a failure-oriented vertical slice.
The target is not “supports everything.” It is “unsupported behavior can be added without corrupting existing guarantees.” That is a substantially more testable definition of reusable commerce architecture.

## Sources returned by Perplexity (not automatically verified)

1. [github](https://github.com/Sylius/Order/blob/master/Model/OrderItem.php)
2. [https://docs.lunarphp.com/1.x/getting-started/overview/introduction](https://docs.lunarphp.com/1.x/getting-started/overview/introduction)
3. [https://docs.lunarphp.com/1.x/reference/orders](https://docs.lunarphp.com/1.x/reference/orders)
4. [https://github.com/lunarphp/lunar](https://github.com/lunarphp/lunar)
5. [https://devdocs.bagisto.com/product-type-development/getting-started.html](https://devdocs.bagisto.com/product-type-development/getting-started.html)
6. [https://docs.bagisto.com/product-types/products.html](https://docs.bagisto.com/product-types/products.html)
7. [https://github.com/bagisto/bagisto/blob/master/packages/Webkul/Sales/src/Models/OrderItem.php](https://github.com/bagisto/bagisto/blob/master/packages/Webkul/Sales/src/Models/OrderItem.php)
8. [https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/Sales/etc/db_schema.xml](https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/Sales/etc/db_schema.xml)
9. [https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/basics/selection-reservations](https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/basics/selection-reservations)
10. [https://experienceleague.adobe.com/en/docs/commerce-admin/b2b/quotes/quotes](https://experienceleague.adobe.com/en/docs/commerce-admin/b2b/quotes/quotes)
