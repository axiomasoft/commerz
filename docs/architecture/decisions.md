# Decision log

Accepted architecture decisions of Commerz. Each entry states the decision and
the one-line reason; options and evidence live in plan `PLAN1`
(`plans/2026.10.08-№1-COMMERZ-FOUNDATION/`) and the research corpus. A new
decision gets the next number; a reversed decision keeps its number and is
marked `superseded by Dn`.

Decided by: the owner for D1; the agent under the owner's delegation (see
`CLAUDE.md`, "Owner delegation") for the rest.

| ID | Status | Decision | Reason |
|---|---|---|---|
| D1 | accepted | Stack: PHP ^8.4, Laravel ^13 only. | Owner's instruction; one compatibility matrix on the current stack. |
| D2 | accepted | Database: PostgreSQL ≥ 16 only (CI on 17). | Stock, price and limit invariants rely on `EXCLUDE`, partial unique indexes, `jsonb` and transactional DDL; CoreX is PostgreSQL-only. MySQL only by a later decision with a real consumer. |
| D3 | accepted | Packages: core + catalog, inventory, pricing, promotions, shipping, sales (+ full, testkit); feature packages depend only on the core and interact through core ports. | Each package installs alone (stock without catalog, CRM sales without catalog) and P2–P7 develop in parallel. |
| D4 | accepted | Stores and channels are a core module (`Commerz::stores()`, `channels()`). | The channel is part of every operation's context; a separate package would become a second core. |
| D5 | accepted | Coupons are a module of `commerz-promotions`, plugged in as activator `coupon` through the public SPI. | Keeps the promotion engine coupon-agnostic (SPI dogfooding) without an extra release unit. |
| D6 | accepted | Tenancy: no `tenant_id`; isolation by the connection or database the host provides; many stores and channels per database. | Matches CoreX ADR-004 and Flex CRM and keeps keys simple; shared-schema SaaS would need its own plan before new migrations. |
| D7 | accepted | Keys: application-generated UUIDv7; append-only journals `bigint identity`; host and cross-package references `*_ref varchar(191)` `type:id`. | Ecosystem canon (CoreX ADR-003, Chatom); IDs exist before `INSERT`; no configurable strategy matrix. |
| D8 | accepted | Money: `int` minor units + currency, `brick/math` with explicit rounding, largest-remainder allocation; quantities `int` in the stock item's base unit; rates in basis points. | Exact sums and simple invariants without floats; weighted goods in g/mm with a sale step. |
| D9 | accepted | Product data: operational fields as columns, variant axes relational (`UNIQUE option_signature`), other attributes as a JSONB document validated by `attribute_type` codecs, filterable ones projected synchronously into `cmz_attribute_index` with `variant_id`. | One-row product reads, correct facets right after save, new attributes without migrations. |
| D10 | accepted | Stock: `cmz_stock_levels` (on_hand, reserved) + append-only `cmz_stock_movements` + `cmz_stock_reservations` by demand; reserve with a conditional `UPDATE`; `ReservationTiming` default `OnOrderPlaced`, option `OnCheckout` with TTL. | Simple and proven under concurrency; matches commercetools reserve-on-order and the Vendure 3.7.4 lesson. |
| D11 | accepted | `PlaceOrder` is one local transaction (receipt → order snapshot → reserve → consume usage → outbox) in the global lock order; payment is a separate command after commit; `OrderState`, `PaymentState`, `FulfillmentState` are independent enums. | All packages share one connection, so no saga is needed and no network call runs under locks. |
| D12 | accepted | API: a root `final readonly` API class per package + facade; domain contracts; command/query DTOs → views/results; Eloquent only in `Internal/Models`; no model swapping. | Clean ecosystem API with Laravel development speed; hidden models can be replaced later without a BC break. |
| D13 | accepted | Promotion limits: entering a code never consumes; consumption happens in the `PlaceOrder` transaction; restore on expiry of an unpaid order and cancellation before payment (`promotions.restore_on`). | No claim phase; matches Saleor and commercetools; abuse is bounded by payment due time and auto-cancel. |
| D14 | accepted | Shipping owns zones, methods, rates, carriers, `DeliveryOptions` and `ShipmentBooking`; sales owns order fulfillments (shipped lines, reservation consumption, tracking). | "Delivery costs 300" is a quote; "parcel shipped" is order bookkeeping that keeps `ordered = cancelled + fulfilled + outstanding` with the order owner. |
| D15 | accepted | Stackable promotions within one stage apply sequentially to the already reduced price (10 % + 5 % = 14.5 %), ordered by `priority DESC, key ASC`. | Totals never go negative and the result is predictable and budget-safe; the owner may revisit before P5. |
| D16 | accepted | Delivery strategy: rolling wave — execute P1, detail P2–P7 in one pass against the real kernel, run them as parallel lanes, then detail and execute P8. | Designs of feature packages are checked against real code, not guesses. |
| D17 | accepted | Catalog axes are a separate module `Catalog::options()`; a variant's `option_signature` is built from the sorted UUIDs of its option values; attribute `type` and `config` are immutable after `define` (name, `filterable`, status and new choices change); choices and option values are never deleted; every sellable is a variant (a simple product has the empty signature); the catalog holds no prices. | An option is not a specification; CAT-01 holds literally over normalized IDs; no live type migration and no type-change race. |
| D18 | accepted | After-commit events are the public core contract `Commerz\Operations\Contracts\CommittedEvents::dispatch()` (immediately outside a transaction); stores moved onto it. | Every feature package needs the same policy; one contract instead of per-package copies. |
| D19 | accepted | Catalog search is `Products::list(ListProducts)` with typed `AttributeFilter` / `VariantFilter` compiled to SQL (same-variant semantics, keyset by `handle`); no indexer port and no facet counts in 0.1. | SQL filters prove CAT-03 now; an indexer SPI is added later without breaking SemVer. |
