# 02-inventory-reservations

- Status: completed
- Method: searchDeep
- Started (UTC): 2026-10-08T14:02:03.933Z
- Finished (UTC): 2026-10-08T14:04:18.188Z
- Transport: local perplexity-web-mcp daemon client via shell

## Question

Research inventory and reservation correctness for a modular PHP/Laravel commerce library. Use primary official sources from Adobe Commerce Inventory Management reservations/source selection, Shopify InventoryItem/InventoryLevel and quantities, Sylius inventory, and PostgreSQL locking/isolation documentation. Explain warehouse vs location vs sales channel vs stock/sales pool, physical quantity vs reserved vs available vs committed, reservation expiration and compensation, checkout/payment timing, concurrent checkout oversell prevention, idempotency, transfers in transit, returns/quarantine, lot/serial/bundle extension boundaries. Compare append-only movement ledger plus projection versus mutable balance with audit ledger, showing one transaction-level concurrency protocol and failure/recovery scenarios. Distinguish source facts from proposed design and do not infer proprietary SaaS physical schemas. Include exact primary URLs and note any contradictory terminology across systems.

## Unverified Perplexity answer

> Это сырой текст DOM-извлечения Perplexity. Таблицы, формулы и некоторые заголовки могли потеряться; отсутствие строки не означает отсутствие раздела в исходном ответе. Формулировки «verified/fact» внутри ответа принадлежат Perplexity. Собственная проверка — в [verified-findings.md](verified-findings.md). Список sources, возвращённый клиентом, содержит по 10 элементов и не считается полным списком всех использованных страниц.

## Recommendation

For a modular PHP/Laravel commerce library, make inventory the exclusive owner of quantity changes, reservations, and allocation invariants. Keep payment status, order status, and physical stock status separate.
My proposed default is:
- Mutable, transactionally updated balances for admission control.
- An immutable movement/reservation journal written in the same database transaction.
- Explicit reservation lifecycle records, including expiration and outstanding quantity.
- Location-level allocation when accepting an order, even if the storefront presents an aggregated sales pool.
- A transactional outbox for payment, fulfillment, and integration work.
- Optional ledger-authoritative operation later, without changing the public inventory commands.
The essential distinction is not “ledger versus balance.” It is whether checking availability and accepting a demand happen atomically against a shared, authoritative contention point. PostgreSQL’s ordinary READ COMMITTED reads do not provide that guarantee by themselves; row locking or a correctly guarded update can provide it for predetermined balance rows.
Below, “source fact” describes the documented systems. “Proposed design” describes the library I recommend—not an assertion about those systems’ implementations.

## 1. What the official systems actually model


## Adobe Commerce: source inventory versus stock-level reservations

Source facts:
- A source is a fulfillment location: a warehouse, store, or other place managing inventory or providing services.
- A stock is a virtual aggregation connecting sources to sales channels. A stock can serve multiple channels; a channel has one assigned stock.
- Sources can be reused in different stocks.
- Reservations are made at (stock, SKU) scope when an order is submitted, not against a selected physical source.
- The Source Selection Algorithm recommends sources and quantities for fulfillment. Merchants can override its recommendations.
- Physical-product shipment deducts source quantity and creates a compensating reservation. Virtual/downloadable products follow an invoice-based path instead.
Adobe explicitly documents append-only reservation entries: an initial negative quantity followed by positive compensations, with a completed order’s reservation sequence totaling zero. Example: order −25, cancel +5, ship +20. Existing reservation entries are not modified.
Important limitation: this establishes an append-only reservation mechanism, not an append-only ledger for all physical inventory movements.

## Shopify: inventory identity and location-specific quantity states

- InventoryItem represents inventory for a product variant, including inventory-related properties such as SKU and tracking configuration.
- InventoryLevel connects an inventory item to a location and exposes named quantities.
- These are public API objects. They are not evidence of Shopify’s physical database tables or locking implementation.
Shopify documents:
incoming is outside this sum because it is not physically present at the destination. The component states are mutually exclusive.
Order placement decreases available and increases committed. Fulfillment decreases committed. Committed also covers reserved draft-order inventory and transfers or shipments marked ready to ship; it is not synonymous with “paid order.” Shopify manages committed through these workflows rather than allowing ordinary Admin API quantity adjustments to that state.

## Sylius: variant-level onHand and onHold

- The documented stock level is stored on ProductVariant as onHand.
- Checkout completion puts inventory onHold before payment, without reducing onHand.
- When payment becomes paid, sell reduces both onHold and onHand.
- release, giveBack, and cancel handle release and restoration.
- Sylius Core’s OrderItemUnit implements InventoryUnitInterface.
This is a materially different timing model from Adobe: Sylius’s documented onHand decreases at payment, whereas Adobe physical source quantity decreases at shipment. Therefore, Sylius onHand should not automatically be interpreted as a warehouse’s literal physical count until dispatch.
An InventoryUnit interface also does not, by itself, establish serial-number traceability.

## 2. Vocabulary: warehouse, location, channel, and sales pool


## Proposed domain definitions

Adobe’s source is closest to a fulfillment location, and its stock is closest to a sales pool. Shopify’s InventoryLevel is a location-specific quantity view, not an Adobe-style stock aggregate. Sylius’s cited core documentation describes variant-level stock, rather than that same multi-source model.

## Shared sources are a critical boundary

Proposed design:
Suppose pools A and B both expose a warehouse containing ten units. If each maintains an independent “ten available” balance and only subtracts its own reservations, both could accept ten orders.
Choose one explicit policy:
- Shared physical allocation: both pools compete for the same location-level capacity.
- Partitioned entitlements: assign, for example, six units to A and four to B.
- A canonical shared reservation domain: all overlapping pools consume the same SKU capacity, with eligibility checks inside that protocol.
For a first release, I recommend shared location allocation. Pools provide visibility, routing, and policy; they do not create additional inventory.
Adobe documents source reuse across stocks, but that fact alone does not establish a cross-stock concurrency guarantee. Do not infer one from its administrative terminology.

## 3. Quantities and contradictory terminology


## Proposed accounting model

Use explicit names:
- physical_on_hand: goods physically present, including unsellable goods.
- blocked: damaged, quarantine, inspection, or other non-sellable quantity.
- safety_stock: deliberate protection against uncertainty.
- checkout_held: temporary checkout claims.
- order_committed: accepted-order claims awaiting physical issue.
- transfer_committed: goods allocated to outbound transfers.
- available_to_promise: quantity that can still be promised under the relevant policy.
For a simple no-backorder location:
where PPP is physical on-hand; BBB, blocked; SSS, safety stock; HHH, checkout holds; CCC, order commitments; and TTT, outbound-transfer commitments.
These deductions must not overlap. A checkout hold becoming an order commitment is a reclassification, not another deduction.
For an eligible pool, aggregate location ATP only where supply is genuinely interchangeable. Shipping region, lot expiry, split-shipment policy, and pickup requirements may make a raw sum insufficient.

## Terms that should not be translated mechanically

These differences are explicit in the respective documentation.
Adobe’s documentation also contains ambiguities:
- General pages say reservations track the shopping process, including cart activity; the detailed lifecycle states reservation creation occurs on order submission. Do not infer a cart-level TTL reservation feature from the general wording.
- “Stocks and sources” describes subtracting reservation amounts, while the detailed example adds signed negative reservations: 55 + (−15) = 40. The latter is the useful signed convention.
- Threshold language varies between pages, including references to notification and out-of-stock thresholds. These passages are not a sufficiently precise specification for reproducing every threshold/backorder combination.
Adapter mappings should therefore express business meaning, not merely rename fields.

## 4. Checkout, payment, expiration, and compensation


## What the sources establish—and do not establish

Adobe places reservations at order submission and resolves them through order events and compensations. Its cleanup cron removes compensated zero-sum sequences; that is not the same as expiring unpaid reservations. Sylius documents checkout holds before payment and selling on payment. Shopify documents order/draft-order commitments but the cited inventory pages do not establish a universal checkout expiration or payment-authorization policy.

## Proposed lifecycle

```
active checkout hold ├── expires / canceled → released └── accepted order → committed ├── canceled remainder → released └── dispatched quantity → consumed
```
For partial fulfillment, model quantities as well as status:
```
original_qty = released_qty + consumed_qty + outstanding_qty
```
A single enum is insufficient for “three shipped, two canceled, five still outstanding.”
Recommended timing:
- Browsing/cart: show advisory availability; no default durable reservation.
- Final checkout submission: atomically create the pending order and hold supply.
- Payment processing: outside the inventory transaction.
- Accepted payment outcome: convert an eligible hold to commitment.
- Dispatch: reduce physical quantity and the corresponding commitment together.
Example with ten physical units:
Payment acceptance must not reduce ATP a second time. Dispatch must not briefly release a claim before reducing physical quantity.

## Expiration is a state transition, not a timestamp filter

- Store expires_at, but release capacity only through an atomic expiration command.
- The worker locks the reservation, verifies its current status and deadline using database time, and releases only its outstanding quantity.
- Payment acceptance and expiration use the same reservation serialization point.
- Once committed, a claim does not retain the checkout TTL; cancellation policy controls its release.
- Extensions require an explicit command and policy.
If payment arrives after expiration:
- Try to reacquire inventory atomically.
- If unavailable, void authorization, refund capture, or place the order into a declared exception/backorder state.
- Never silently resurrect an expired reservation.
This also requires a payment orchestration record: an authorization request may still be in flight when expiration becomes due. Define whether that state permits release, grants a bounded extension, or requires provider reconciliation.
Compensation reverses the outstanding effect of a specific operation. It does not erase history and does not imply that goods physically returned.

## 5. Ledger plus projection versus balance plus audit journal


## Proposed comparison

A ledger does not inherently prevent overselling. Two transactions can both read a ledger sum of one and each append a reservation unless access is serialized or protected by an appropriate isolation protocol. PostgreSQL REPEATABLE READ can still permit serialization anomalies; SERIALIZABLE detects such conflicts and requires whole-transaction retries.

## Recommended implementation boundary

Start with balance plus immutable journal, but require:
- Every inventory command updates balance, reservation/allocation records, and journal atomically.
- Events contain sufficient accounting effects for reconciliation and optional reconstruction.
- No host application, admin screen, import, or queue consumer updates counters directly.
- Search/storefront projections may be asynchronous.
- The projection used to admit demand may not lag.
If the journal later becomes authoritative, retain the same command protocol and make the admission balance its synchronously maintained projection.
Adobe’s append-only reservation model provides a useful precedent for compensation, but its documentation does not establish a permanent full movement ledger: completed reservation sequences can be deleted by cleanup.

## 6. One transaction-level concurrency protocol


## Preconditions

Proposed protocol for a modular monolith using one PostgreSQL database:
- One stable balance row per (tenant, inventory_item, location).
- All capacity-changing operations follow the same locking discipline.
- Eligibility/pool configuration is stable or version-checked during allocation.
- Exact integer units or fixed-precision quantities; not binary floating-point counters.
- Unique command keys, reservation identifiers, journal-event keys, and fulfillment operation keys.
- Checkout aggregates duplicate SKU/component demand before allocation.
Use a consistent global lock order: command record, applicable lifecycle/order record, then balance keys in sorted order. Expiration, payment, shipment, cancellation, and transfer commands must respect the same order.

## Checkout transaction

```
BEGIN ISOLATION LEVEL READ COMMITTED; INSERT INTO inventory_commands (tenant_id, operation, idempotency_key, request_hash, status) VALUES (:tenant, 'checkout_reserve', :key, :hash, 'processing') ON CONFLICT DO NOTHING; SELECT * FROM inventory_commands WHERE tenant_id = :tenant AND operation = 'checkout_reserve' AND idempotency_key = :key FOR UPDATE;
```
Application behavior:
- Reject the same key with a different request hash.
- Return the stored result for a completed command.
- Otherwise continue processing.
Then:
- Lock or create the order/checkout lifecycle record.
- Lock all candidate balance rows in deterministic key order.
- Read the locked balances and choose an allocation satisfying eligibility and quantities.
- Reject if the complete basket cannot be allocated.
- Apply guarded increments:
```
UPDATE inventory_balances SET checkout_held = checkout_held + :quantity, version = version + 1 WHERE tenant_id = :tenant AND inventory_item_id = :item AND location_id = :location AND physical_on_hand - blocked - safety_stock - checkout_held - order_committed - transfer_committed >= :quantity RETURNING *;
```
- Require one updated row for every allocation; otherwise abort the transaction.
- Insert reservation and allocation records.
- Insert immutable journal entries.
- Insert pending order and payment-request outbox records.
- Store the command’s result and commit.
The guarded update matters because, at READ COMMITTED, PostgreSQL waits for competing updates and re-evaluates the predicate against the updated row. A lock acquired through SELECT FOR UPDATE also waits and returns the updated row. Thus, when only one unit remains, a second checkout cannot successfully increment the hold after the first consumes that capacity.
For multi-row allocation, all changes commit or none do. Deterministic lock ordering reduces deadlocks; PostgreSQL can still abort transactions when deadlocks occur, so retry the entire transaction. Serializable failures likewise require complete retries, not replaying just the last SQL statement.

## Important implementation constraints

- SELECT FOR UPDATE cannot protect a nonexistent balance row. Pre-create balance rows, or create them through a unique-key-safe protocol before depending on their locks.
- Do not execute provider HTTP calls, user interaction, or fulfillment dispatch inside the transaction.
- Keep network retries separate from database retries.
- In Laravel, put this protocol in an inventory application service; model observers must not independently create stock effects.
- Do not use SKIP LOCKED to conclude that inventory is unavailable merely because another checkout holds a lock. It is appropriate for distributing background work, not establishing a complete supply view.
- Distinct idempotency keys do not prevent duplicate business operations. Also enforce identities such as (order_line, shipment_line, inventory_effect_type).
PostgreSQL row locks block conflicting writers/lockers until transaction end; they do not make ordinary unlocked reads authoritative.

## 7. Failure and recovery scenarios

The following are proposed behaviors.
A “no overselling” database protocol prevents conflicting application promises against recorded capacity. It cannot eliminate theft, damage, wrong receipts, or delayed external adjustments. The model needs explicit shortage handling rather than pretending those events cannot occur.
Adobe provides inconsistency-detection and compensation CLI tooling, and warns that source unassignment with pending orders can leave stuck reservations. This supports treating reconciliation and configuration-change safety as operational requirements, not optional reporting.

## 8. Transfers, returns, and quarantine


## Transfers in transit

Shopify documents incoming quantity at the destination as not physically present and unavailable until receipt; ready-to-ship transfers or shipments can create committed quantity.
Proposed transfer flow:
- Allocate outbound quantity: reduce source ATP, not source physical stock.
- Dispatch: reduce source physical stock and outbound commitment; increase a transit account.
- Receive: decrease transit and increase destination physical stock, usually into inspection or available stock according to policy.
- Record loss, damage, and quantity discrepancy separately.
A transfer is not an instantaneous source decrement plus destination availability increment. Partial dispatch and partial receipt need independently idempotent line operations.
Destination incoming is a view of expected supply, not additional owned units to add again to source and transit totals.

## Returns and quarantine

Shopify explicitly separates quality-control and damaged inventory from available inventory. Sylius’s documented giveBack restores its stock counter; that should not be copied indiscriminately into a physical warehouse return process.
Proposed return flow:
```
return authorized → physically received into quarantine → inspected ├── sellable → available ├── repair/refurbishment └── damaged/disposal
```
Keep these operations distinct:
- Financial refund.
- Cancellation of an unfulfilled commitment.
- Physical receipt of returned goods.
- Release from quarantine.
Refunding a dispatched order must not create physical stock. Canceling an unshipped order releases its outstanding commitment without adding physical quantity.

## 9. Lot, serial, and bundle extension boundaries

These are proposed extension contracts, not claims about native functionality in the cited platforms.
For virtual bundles, normalize the basket before locking: two bundles and a separately purchased component may compete for the same SKU. Store the accepted component/BOM snapshot so later catalog changes do not rewrite an existing order’s demand.
For lot-controlled goods, reserve an eligible lot at checkout or prove that a pooled promise can later be satisfied under expiry and delivery constraints. For serialized goods, SKU-level reservation can precede serial picking only if the final serial assignment is atomic and the policy permits deferred assignment.
Avoid adding nullable lot_id and serial_id columns everywhere without first defining the identity of an allocation key. Extensions must participate in the same transaction and contention protocol—not receive an asynchronous “reservation created” event and decide later whether stock actually exists.

## 10. Modular ownership and verification


## Proposed ownership

- Catalog: sellable variants, inventory-item references, bundle definitions.
- Inventory: physical balances, holds, commitments, allocations, movements.
- Orders: accepted demand and order-line lifecycle.
- Payments: authorization, capture, refund, provider reconciliation.
- Fulfillment: pick/pack/dispatch and shipment identity.
- Returns: receipt and inspection workflow.
- Integration adapters: explicit terminology mapping and external reconciliation.
In a modular Laravel monolith, checkout can compose Orders and Inventory commands inside one local transaction without giving Orders direct write access to inventory tables. A later service split would require an explicit saga/pending-state protocol; it should not silently retain an atomicity guarantee that no longer exists.
Minimum correctness tests:
- Two concurrent checkouts competing for the last unit.
- Two pools sharing the same physical location.
- Multi-line checkout failure after one tentative allocation.
- Payment versus expiration in both execution orders.
- Partial shipment plus cancellation with repeated delivery.
- Transfer dispatch/receipt retries and shortages.
- Returned goods remaining unavailable until inspection.
- Bundle components shared across order lines.
- Ledger replay/reconciliation matching balances and outstanding reservations.
For Shopify adapters specifically, do not assume inventory webhooks provide a complete state-change stream: its documentation says changes to committed, reserved, damaged, safety-stock, and quality-control states do not trigger those webhooks. Reconciliation must account for this limitation.

## Primary-source URLs and scope

These are the exact official URLs used. Shopify latest and PostgreSQL current are moving references; the PostgreSQL pages retrieved identify version 18. The claims above describe documentation/API behavior, not an inferred Shopify SaaS schema.

## Adobe Commerce

- Sources, stocks, channels, quantities:https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/basics/sources-stocks
- Source selection, reservation lifecycle, append-only compensation, cleanup:https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/basics/selection-reservations
- Reservation inconsistency/compensation CLI:https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/cli

## Shopify

- Inventory identity:https://shopify.dev/docs/api/admin-graphql/latest/objects/InventoryItem
- Item/location quantity API:https://shopify.dev/docs/api/admin-graphql/latest/objects/InventoryLevel
- State definitions, lifecycle, transfer commitments, webhook limitations:https://shopify.dev/docs/apps/build/orders-fulfillment/inventory-management-apps
- Quantity mutations and documented compare-and-swap controls:https://shopify.dev/docs/apps/build/orders-fulfillment/inventory-management-apps/manage-quantities-states

## Sylius

- Inventory counters, checkout hold, payment sale, release/give-back:https://docs.sylius.com/the-book/products/inventory

## PostgreSQL

- Isolation, guarded-update behavior, serialization failures:https://www.postgresql.org/docs/current/transaction-iso.html
- Row locks, lock lifetime, deadlocks, advisory-lock semantics:https://www.postgresql.org/docs/current/explicit-locking.html

## Sources returned by Perplexity (not automatically verified)

1. [experienceleague.adobe](https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/basics/sources-stocks)
2. [experienceleague.adobe](https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/basics/selection-reservations)
3. [shopify](https://shopify.dev/docs/apps/build/orders-fulfillment/inventory-management-apps)
4. [sylius](https://docs.sylius.com/the-book/products/inventory)
5. [postgresql](https://www.postgresql.org/docs/current/transaction-iso.html)
6. [postgresql](https://www.postgresql.org/docs/current/explicit-locking.html)
7. [https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/cli](https://experienceleague.adobe.com/en/docs/commerce-admin/inventory/cli)
8. [https://shopify.dev/docs/api/admin-graphql/latest/objects/InventoryItem](https://shopify.dev/docs/api/admin-graphql/latest/objects/InventoryItem)
9. [https://shopify.dev/docs/api/admin-graphql/latest/objects/InventoryLevel](https://shopify.dev/docs/api/admin-graphql/latest/objects/InventoryLevel)
10. [https://shopify.dev/docs/apps/build/orders-fulfillment/inventory-management-apps/manage-quantities-states](https://shopify.dev/docs/apps/build/orders-fulfillment/inventory-management-apps/manage-quantities-states)
