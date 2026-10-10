# Invariants, transactions and lock order

The registry of business invariants Commerz guarantees, who enforces each one,
which bypass it closes and how it is tested. Every invariant has one owner and
one enforcement point; adding a write path that skips it is a defect.

## Invariant registry

| ID | Invariant | Owner / enforcement point | Bypass closed | Test |
|---|---|---|---|---|
| INV-01 | A keyed command applies at most once in its scope; a retry with another payload is a conflict | `OperationReceipts::once` (core) + the owner's natural unique key | calling an `Internal` handler directly (arch gate: `Internal` is closed) | retry, concurrent retry, different payload |
| INV-02 | `reserved ≥ 0`, `on_hand ≥ 0`, `safety_stock ≥ 0` per level (`CHECK`); a reservation of a tracked item never makes `reserved > on_hand − safety_stock`; the excess exists only for `backorder` items or after a physical decrease (`adjust`/`count`), which publishes `StockShortfall` | inventory `StockLedger::hold`: conditional `UPDATE … RETURNING` under the level lock; allocation is computed over the locked rows | read-then-write in PHP; allocating from an unlocked read (Vendure ≤ 3.7.3) | 8 processes × last unit; 8 × a kit with a shared component |
| INV-03 | Every `on_hand` change is exactly one `cmz_stock_movements` row in the same transaction, and `balance_after` is the new `on_hand` | inventory `StockLedger`, the only writer of levels and movements (`LedgerBoundaryTest`: the logical names `stock_levels` / `stock_movements` appear only in `StockLedger`, `LevelQueries` and `Reconciler`) | writing a level without a movement | `commerz:inventory:reconcile` reports 0 differences after concurrent load; a corrupted `on_hand` is reported, never repaired |
| INV-04 | Reservation line: `units = consumed + released + expired + outstanding`, `outstanding ≥ 0`; `reserved(level) = Σ outstanding × quantity_per_unit`; consume, release and expire never free a unit twice | `CHECK` on the line; the demand header `FOR NO KEY UPDATE` serializes consume, release and expire of one demand; lines `FOR NO KEY UPDATE`; expiry takes foreign headers `SKIP LOCKED` only | the sweeper racing consume; a double release | expire vs consume race (2 × 20 repeats); release twice; `--repair-reserved` rebuilds `reserved` from the lines |
| INV-05 | One demand holds at most one set of reservations; retrying `reserve` never reserves twice | `UNIQUE(demand)` on the header, `INSERT … ON CONFLICT DO NOTHING` as the first step | two parallel reserves of one demand | 4 processes × one demand → one set of lines |
| INV-06 | Transfer: `received + lost ≤ quantity` per line; dispatch takes the stock from the source exactly once | transfer row `FOR NO KEY UPDATE` + state machine (draft → in_transit → completed \| cancelled); source stock held by a reservation of demand `transfer:{id}` from creation; `CHECK` on the line | double dispatch or receive | dispatch twice → `InvalidTransferState`; repeated receive with a key |
| CAT-01 | An axis combination is unique within a product | catalog: `UNIQUE(product_id, option_signature)` over the sorted UUIDs of the option values + composite foreign keys of `cmz_variant_option_values` (value ∈ option ∈ the product's axes) + product row `FOR NO KEY UPDATE` in every variant-structure mutation | generating variants from two requests; inserting a value of a foreign option | concurrent generate (exactly N variants, no exception); raw insert of a foreign value → foreign-key error |
| CAT-02 | An attribute value passed its type and domain for every writer | catalog `AttributeWriter`, the only writer of attribute documents and `cmz_attribute_index`; definitions read `FOR SHARE` while writing; imports go through the contract | direct JSONB `UPDATE`; a write racing a status or `filterable` change | import with an invalid value leaves document and projection untouched; arch test: only the writer writes `attributes` documents |
| CAT-03 | Facet `red AND M` means one variant with both values | catalog `ProductQuery`: every filter of a search sits inside one `EXISTS` over an active variant (`cmz_attribute_index.variant_id`, `cmz_variant_option_values`) | filtering by product instead of variant | red/S + blue/M → 0 results |
| PRC-01 | Validity windows of one price (list, sellable, group, min quantity) never overlap | pricing: `cmz_prices_window_ex` `EXCLUDE USING gist` over `tstzrange(valid_from, valid_until, '[)')` (`btree_gist`); `SetPrice` / `reschedule` map SQLSTATE `23P01` to `PriceWindowOverlap` | two parallel `SetPrice`; `reschedule` into another window | 6 processes, overlapping windows → 1 row |
| PRC-02 | The same context and data always select the same price; the ranking is a total order | pricing `PriceSelection` (pure; priority DESC → lowest amount → group → min quantity DESC → list code → price id) shared by `resolveMany` and `explain` | "first row" selection without `ORDER BY`; a separate explain path that drifts from resolve | ranking matrix, input permutation, `explain` equals `resolve` |
| TAX-01 | Validity windows of one rate (class, zone, tax code) never overlap; a zone member `(country, region)` belongs to one zone | pricing: `cmz_tax_rates_window_ex` `EXCLUDE USING gist`; `UNIQUE NULLS NOT DISTINCT (country, region)` on zone members | parallel `SetTaxRate`; the same member in two zones | 6 processes → 1 rate; member taken → `TaxZoneMemberTaken` |
| TAX-02 | Tax is computed on the line base after all discounts (the same order-level shares `totals` settles), never exceeds an included base, and rounds per line with the configured mode | pricing `TaxStage` + core `QuoteState::allocatedOrderAdjustments()` / `deliveryNet()` (D23) + `TaxResultValidator` | tax on the pre-discount price; a second allocation algorithm in the stage; a calculator returning junk | golden tests G1–G14, `PricingScenarioTest` |
| PRM-01 | Promotion, coupon and per-customer limits and budgets are never exceeded concurrently; a limit is spent only when the order is placed, never when a code is entered; changing a limit loses no parallel spend | promotions `DatabasePromotionUsage::consume`: redemption header by `orderRef`, then coupons → promotions → customer usages (id ASC), `FOR KEY SHARE` plus conditional `UPDATE` / `INSERT … ON CONFLICT DO UPDATE … WHERE`; definition mutations take `FOR UPDATE` and call `UsageCounters::recompute` (D27) | spending at quote time; read-then-write of a limit in PHP; a limit change without a lock; a hot counter on every unlimited promotion | limit 10 with 40 concurrent orders; coupon, per-customer and budget limits; opposite lock orders; same-order retry; limit change during consume (`UsageConcurrencyTest`) |
| PRM-02 | Discounts allocated to lines sum exactly to the total discount; what a promotion took off a quote (`appliedPromotions`) is the sum of its adjustments, order-level shares counted once | core `QuoteState::allocatedOrderAdjustments()` (D23) + `QuoteResult::appliedPromotions()` (D25), the one fold sales and the tests share | rounding each line separately; a second allocation or fold in promotions or sales that double-counts order shares | `AppliedPromotionsTest`, golden tests G7 and G21, `PromotionsScenarioTest` |
| PRM-03 | A coupon code is unique per installation in its canonical form, and input is matched the way uniqueness is checked | `cmz_coupons.code` `UNIQUE` + `CHECK` on the canonical form; `PromotionCodes::couponCode` (trim + upper case) is the only normalization of writes and lookups | `AUTUMN` and `autumn` as two coupons; a generation collision; a case-sensitive lookup | `SchemaTest`, golden test G17, concurrent generation, `PromotionsScenarioTest` (code `' autumn '`) |
| PRM-04 | The same input and data give the same adjustments in a total order | `ActivePromotions` loads `order by stage, priority desc, code asc`; rules run by `position`; `buy_x_get_y` sorts units by (value, line index) | "as the database returned them"; float arithmetic; an unstable sort | golden test G2 with permuted creation order |
| PRM-05 | A promotion applies whole or not at all, and never takes a line, the order or the delivery below zero; a plugin's result is not trusted | `PromotionEngine` works on a temporary state and keeps it only when every effect passes `DiscountGuard` (currency, target, lines, ≥ 0, clamping); `ActivationResult` and `ConditionOutcome` are validated | partial application when a budget or guard refuses; an effect returning junk or a foreign currency; a discount above the price | golden tests G7 and G16, `RuleTrustTest` |
| SHP-01 | For one input, a method's price in `DeliveryOptions`, `list`, `explain` and the `delivery` quote stage is the same | shipping `MethodPricer`, the only price path; the stage only supplies the goods base | a second price calculation in the stage | options vs quote (G14) |
| SHP-02 | A selected method that cannot deliver to the address, channel, currency or weight never yields a quote without delivery | `DeliveryStage` → `QuoteFailed(method_unavailable)` | silently dropping the delivery | unavailable method (G12) |
| SHP-03 | At most one carrier call per `reference` at a time; after an unknown outcome `find` runs before any new booking | `shipping_bookings`: `UNIQUE(reference)`, `ON CONFLICT`, `FOR UPDATE`, lease, `attempts` fencing | retry after a timeout; calling `Carrier` directly | concurrent booking C1, C3–C5 |
| SHP-04 | A carrier call never runs inside a database transaction | `CarrierShipmentBooking` refuses an ambient transaction (`shipping.booking_inside_transaction`) | booking from a host `Transactions::run` that later rolls back | booking inside a transaction |
| SHP-05 | A tariff with an unknown or outdated rule, or an unknown carrier, is not offered (fail-closed) but stays visible to the admin | `RateCodec::decode` → unsupported; verdicts `unsupported_rate`, `unsupported_carrier`; `RateView::$unsupported` | applying a tariff with a broken config | unsupported rate (G9) |
| SHP-06 | Zone choice is deterministic: specificity rank, then `zone.code` | `MethodPricer` zone step | database row order | zone ranking (G4, G4b) |
| ORD-01 | Retrying `PlaceOrder` never creates a second order | sales: `UNIQUE(cart_id)` on orders + cart `FOR UPDATE` + `OperationReceipts`; `OrderWriter` is the only writer of `orders` | client timeout and retry; a second insertion path | concurrent double submit (C1, C2) |
| ORD-02 | An order keeps the accepted amounts and snapshots; repricing live data never changes it | sales: snapshot lines, no FK to catalog | refreshing an order from the catalog | change the price after placement |
| ORD-03 | Per line `ordered = cancelled + fulfilled + outstanding`, `returned ≤ fulfilled` | sales `OrderLedger` (conditional `UPDATE`) + `CHECK` | shipping more than ordered; returning more than shipped | partial shipment + cancel (C6) |
| PAY-01 | A provider event applies at most once | sales: `UNIQUE(gateway, external_event_id)` in `PaymentEventInbox::claim` under the order lock | repeated webhook | double webhook |
| PAY-02 | A provider network call never runs inside a database transaction; an unknown outcome is `unknown` and is settled by `find(reference)`, not guessed as `failed` | `PaymentWorkflow` / `RefundWorkflow` (`sales.operation_inside_transaction`); the only callers of the SPI | calling a gateway inside `Transactions::run` | timeout after authorization |
| REF-01 | Refunding money never returns goods to stock automatically; receiving a return is not restocking | sales: stock is touched only by `ReceiveReturn::$restock` | `available += returned` on refund | refund without receiving |
| CTX-01 | A cart or order never changes channel or currency; references from another channel are rejected | sales + `Sellables` (`purchasable` in the channel); the catalog's `variant` provider reports `not_listed` / `not_available_yet` / `no_longer_available` per channel and `context.at` | changing `channel_id`; adding an unpublished, archived or out-of-window variant | adding an unpublished variant; every reason in `VariantSellablesTest` |
| SAL-01 | A payment never exceeds the amount due and a refund never exceeds what the payment captured | balance under the order lock (initiate); refundable under order + payment locks (issue); `CHECK refunded ≤ captured` | two parallel `initiate` / `issue` | C5, C7 |
| SAL-02 | An order with a payment attempt never expires; a capture after cancellation is not lost | expiry re-checks under `FOR UPDATE SKIP LOCKED`; `sales.payment_requires_refund` (D32) | expiry during 3-D Secure | expiry and capture race (C8) |
| SAL-03 | Only the owner of a network lease writes its result | `attempts` fencing of `finish`; `reconcile` takes the lease | a stale process overwriting a reconciled result | `PaymentJournalFenceTest` |
| EXT-01 | A plugin failure never leaves half a mutation | quoting is pure stages outside writes; mutations are one transaction | a plugin with side effects in `apply()` | a stage that throws |

## Order placement transaction

```text
PlaceOrder(cartId, idempotencyKey, expectedTotal)
 outside the transaction:
   1. load the cart (version), Sellables::resolveMany, QuotePipeline::run → QuoteResult
   2. expectedTotal ≠ quote.total → QuoteChanged (the client re-quotes)
   3. allocation strategy (inventory, inside reserve) only proposes
 Transactions::run(lockTimeoutMs: 3000):
   4. OperationReceipts::once('sales.place_order', key, fingerprint(cartId, cart.version, expectedTotal))
      (not quote.fingerprint: it includes the context time, so a retry would conflict)
   5. SELECT cart FOR UPDATE; cart.version matches and cart.state = open, otherwise CartChanged / AlreadyPlaced
   6. INSERT order (UNIQUE cart_id) + snapshot lines + adjustments + taxes + addresses
   7. StockReservations::reserve(demand 'order:{id}', lines)   — level locks ordered by (stock_item_id, location_id)
   8. PromotionUsage::consume(orderRef, applied)                — locks: coupon → promotion → customer usage (id ASC)
   9. cart.state = converted; Outbox::record(OrderPlaced)
 COMMIT → domain events after commit; payment starts with a separate command
```

Payment, carrier booking and any other network call happen after commit as
separate idempotent commands with reconciliation (D11, PAY-02).

## Global lock order

Every multi-resource operation locks in this order:

**cart/order → child documents of the order → stock levels (by `stock_item_id`,
`location_id`) → coupons → promotions → customer usages → payments → refunds**

Any new operation must follow it; a violation is a defect. `lock_timeout` is
3 s. On `LockNotAvailable` or deadlock, only the whole transaction is retried,
at most 3 times with jitter.

Cancelling or expiring an unpaid order (D32: expiry is a sales command over
`payment_due_at`; an order with a payment attempt is skipped) runs in one
`Transactions::run`: order
`FOR UPDATE` → `StockReservations::release('order:{id}')` →
`PromotionUsage::restore(orderRef, Cancelled|Expired)` per the
`promotions.restore_on` policy (default: expired and cancelled before payment)
→ state change → outbox.

## Inventory lock order

Inside the *stock* step of the global order, inventory locks in this order
(D21):

```text
transfer row → reservation header of the demand
  → stock items ORDER BY id, then locations ORDER BY id (FOR SHARE, when resolving stock)
  → stock levels ORDER BY (stock_item_id, location_id), one FOR NO KEY UPDATE statement
  → reservation lines ORDER BY id FOR NO KEY UPDATE
```

`FOR NO KEY UPDATE`, not `FOR UPDATE`: movement and reservation rows reference
the level by foreign key and take `FOR KEY SHARE` on it at `INSERT`. A command
locks every level it needs in one statement; a second lock call can deadlock
against another command. A caller may still consume or release one demand line by
line in one transaction (a fulfillment, a partial cancellation), so `consume`
and `release` of one sellable lock every level the demand still holds at
their first call, not only that sellable's; the later calls find their
levels already held. Lazy expiry and the sweeper take foreign headers only
with `SKIP LOCKED` and only lines on levels already locked, so they never wait
for another demand.
Transfer creation guards the items and locations before inserting its new
reservation header; that unique demand never waits for an existing header.

Item lifecycle changes take the item row `FOR NO KEY UPDATE` before checking
its levels and unresolved transfer lines. A draft or in-transit transfer
blocks archiving the item and changing its policy to `untracked`; stock in
transit still belongs to the item even when its source level is empty.

`commerz:inventory:reconcile` reads one read-only `REPEATABLE READ` snapshot
without locks (`ledger_sum`, `ledger_balance`, `reserved_sum`,
`reservation_state`, `transfer_state`; exit 1 on any discrepancy).
Line-free active demands (untracked or unlimited stock) and expired headers
whose last outstanding lines were lazily expired are valid; the sweeper
closes the latter. A closed header with outstanding lines remains an error.
`--repair-reserved` rebuilds only `reserved` from the reservation lines under
the level locks; `on_hand` is never repaired automatically (correct it with a
count).

Availability and the tracked reservation guard subtract `reserved` and
`safety_stock` in PostgreSQL `numeric` before comparing or clamping the
result. Each stored quantity is `bigint`, but their intermediate difference
can fall below the signed 64-bit range.

## Catalog lock order

Inside the catalog an operation locks in this order, so concurrent catalog
operations never deadlock: 1) the advisory lock of the category tree
(`pg_advisory_xact_lock`); 2) the product row `FOR NO KEY UPDATE`; 3) variant
rows by `id ASC`; 4) attribute definitions by `id ASC` (`FOR SHARE` for the
writer, `FOR NO KEY UPDATE` for `update`); 5) rows of `cmz_attribute_index`.
`FOR NO KEY UPDATE`, not `FOR UPDATE`: inserting a projection row takes
`FOR KEY SHARE` on the product, variant and attribute, which `FOR UPDATE`
would block. The catalog calls no network and publishes no outbox events;
its events are Laravel events dispatched after commit. Repeating a command is
safe through natural keys (`code`, `handle`, `sku`, signature). The
`variant` sellable provider only reads: no transaction, at most 6 queries for
a batch of any size.

## Pricing and tax configuration lock order

Price writes take the price list row `FOR SHARE`, then a transaction advisory
lock for the price key (list, sellable, group, min quantity), then the price row
`FOR NO KEY UPDATE`. `setMany` sorts keys before taking their locks;
`reschedule` follows the same order. List updates and archival take
`FOR NO KEY UPDATE` on the list.

Zone creation and member replacement take one transaction advisory lock scoped
to the `tax_zone_members` table before touching a zone or its members.
Membership is shared configuration: concurrent replacement of two zones can
otherwise deadlock when unique checks wait on each other's deleted members.
Serializing these rare mutations also works inside a host transaction and
keeps a failed claim atomic. Sorting only the inserted pairs does not prevent
this cycle; locking individual members would require locking both the old and
new sets and rechecking membership changes. The membership lock avoids that extra
protocol. It is an advisory key, not a PostgreSQL table lock; readers and rate
writes remain unlocked by it. This is the P4 audit correction to the membership
write algorithm in D24.

Rate writes take the transaction advisory lock of (class, zone, tax code),
then the rate row `FOR NO KEY UPDATE`. Resolution and quoting only read.
Hosts composing mutations across several lists or rate keys must take those
resources in a consistent order for the whole transaction.

## Promotions lock order

The Promotions step of the global order (D27) takes, per placed order, the
redemption header `orderRef` first (one row per order, in the cart/order
class), then **coupons → promotions → customer usages**, each class by `id ASC`
in one statement. A counter exists only while its limit is set (`used` with
`usage_limit`, `spent_minor` with `budget_minor`, `coupons.used` with the coupon
limit, customer rows with `per_customer_limit`), so an unlimited promotion or
a public coupon is never a hot row that serializes placements. PostgreSQL row
lock modes carry the protocol:

| Who | Row | Mode | Conflicts with |
|---|---|---|---|
| `consume` / `restore`, every promotion and coupon of the order | promotions, coupons | `FOR KEY SHARE` | only `FOR UPDATE` |
| `consume` / `restore`, limited ones only | promotions, coupons | conditional `UPDATE` (`FOR NO KEY UPDATE`) | other `NO KEY UPDATE`, `FOR UPDATE` |
| inserting a usage or coupon (foreign key) | promotions, coupons | implicit `FOR KEY SHARE` | only `FOR UPDATE` |
| any definition mutation (update, pause, resume, archive, rules, channels; coupon: disable, enable, set limit) | promotions / coupons | `FOR UPDATE` | everything |
| `GenerateCoupons` / `CreateCoupon` | promotions | `FOR KEY SHARE` | only `FOR UPDATE` |

Upgrading `KEY SHARE` to `NO KEY UPDATE` cannot deadlock: `NO KEY UPDATE` is
compatible with another transaction's `KEY SHARE`, and two upgrades wait for
each other in turn. A definition mutation waits for every transaction that
already holds `KEY SHARE` and blocks new ones, so the recount that follows a
limit change (`UsageCounters::recompute`, from `consume`d usages) is exact.
The status is re-checked by `consume`; the validity window is not (the quote's
`context.at` is the authority). Quoting and `explain` read without locks: old
or new state is acceptable, the limit is enforced at placement. A guest
(`customer = null`) cannot use a promotion with a per-customer limit: the quote
rejects it (`customer_required`) and `consume` throws
`UsageLimitReached(per_customer)`.

Coupon code creation (`create` and `generate`) takes a transaction advisory
lock scoped to `commerz.coupon_codes:<physical coupons table>` before a
promotion row or generation receipt. The lock covers all rounds and remains
held until the outer transaction ends: sorting each round alone cannot prevent
a later round from waiting on an earlier insert of another uncommitted batch.
Administrative code creation is serialized across promotions because codes
are unique across the installation; quoting, placement, restore and coupon
status/limit updates do not acquire this lock. Hosts composing definition
mutations with code creation must call code creation first. This is the P5
audit correction to the generation algorithm.

Line discounts are capped by both `lineNet(lineId)` and the remaining
items total after declared order adjustments. Order discounts remain declared
and are allocated over the final line nets by D23 at settlement; later line
discounts may change the shares, but cannot leave too little to carry their
total. Budget guards compare the discount to `budget - spent`, avoiding
overflow for supported 64-bit minor amounts.

`commerz:promotions:reconcile` is read-only on one `REPEATABLE READ` snapshot:
it recounts `used` / `spent_minor` of limited promotions, `used` of limited
coupons and the per-customer rows from the consumed usages, and lists rules
with an unsupported key, version, stage or parameters; it exits 1 on any discrepancy and repairs
nothing.

## Shipping lock order

Shipping locks inside its own tables in this order: **method row `FOR UPDATE`
→ zone rows `FOR SHARE` by `id ASC`** (`setRates`, `create` with tariffs).
`archive`, `restore` and `setMembers` of a zone lock only that zone
`FOR UPDATE`, so `setRates` × `archiveZone` serialise without a cycle. The
booking journal is a separate resource and is never locked together with them.
A booking runs as claim transaction → carrier call with no transaction →
fenced finish transaction (D29); the lease must exceed the carrier client's
timeout (`commerz-shipping.booking.lease_seconds`, default 120). Quoting,
`list` and `explain` read without locks.

The journal determines whether an attempt is new, a replay or recovery (D30).
Only new operations and fresh attempts after a final rejection validate the
current method; validation runs before the claim commits, and failure rolls it
back. A booked replay returns without resolving any carrier. Recovery uses the
original carrier and fingerprint regardless of later method maintenance; a
missing carrier leaves the outcome unknown and retryable. Stored-rate plugin
failures in version, upgrade or normalization mark that tariff unsupported
without aborting healthy options or admin reads (SHP-05).

## Sales lock order

Inside sales the global order is refined to **receipt (by key) → cart/order →
child documents of the order (fulfillment, return, by id) → stock levels →
coupons → promotions → customer usages → payments → refunds**. Ports are called
inside a transaction only where listed.

| Operation | Locks | Ports inside the transaction |
|---|---|---|
| `Carts::*` | cart `FOR UPDATE` | — (sellables resolve before) |
| `Checkout::place` | receipt → cart → insert order → stock levels → usages | `reserve`, `consume` |
| `cancel`, `cancelLines`, `expireDue` | order (`SKIP LOCKED` for expiry) → stock → coupons/promotions | `release`, `restore` |
| `initiate` Tx A / finish | receipt → order → insert payment / payment `UPDATE` | — |
| `recordEvent`, `reconcile` | order → payment → inbox insert | — |
| `Fulfillments::create` | receipt → order → insert fulfillment → stock levels | `consume` |
| `ship`, `book` (Tx), `markDelivered` | order → fulfillment | — (the carrier call is outside) |
| `Returns::*` | receipt → order → return → stock levels | `restock` |
| `Refunds::issue` Tx A | receipt → order → payment → insert refund | — |
| refund finish | order → promotions (`restore(Refunded)` on a full refund) → payment → refund `UPDATE` | `restore` |

`order_events` and `payment_events` are inserts that never wait on anything but
the same key held by the order owner. `finish` writes only if `attempts` still
matches (SAL-03), so a stale lease owner never overwrites a newer result.
Refund events lock order → claim → restore → payment → refund.

Order completion checks gross captured money against the accepted amount due,
independently of the refund state. Promotion restoration for a full refund
uses the same coverage condition and requires all captured money returned.
Expiry excludes known active-payment and fulfilled orders before its limit,
then advances by `(payment_due_at, id)` after skipped candidates while
rechecking eligibility under each order lock.

Fulfillment booking commits its intent under order → fulfillment locks before
the port call. A manual shipment that overtakes this intent does not suppress
recovery by the same reference: matching tracking attaches the booking details,
and conflicting tracking returns those details in a conflict error. The
Shipping journal remains the owner of carrier deduplication and recovery.

## Interfering operations

| Operation A | Operation B | Shared resource | Protection |
|---|---|---|---|
| reserve (order 1) | reserve (order 2) | stock levels | ordered `FOR NO KEY UPDATE` + conditional `UPDATE` |
| reserve (demand X) | reserve (demand X) | demand header | `UNIQUE(demand)`; the second waits and receives the repeat |
| reserve | adjust / count / receive | stock level | both through `StockLedger::lock` |
| consume (shipment) | expire (worker) | demand header and lines | header `FOR NO KEY UPDATE`; the sweeper takes it `SKIP LOCKED` and re-checks |
| count with `expectedOnHand` | reserve / consume | `on_hand` | the compare-and-set runs under the level lock |
| transfer create / dispatch | reserve at the source | source stock level | the same lock order; the draft holds its stock as a reservation |
| book (reference X) | book (reference X) | `shipping_bookings` row | `UNIQUE(reference)` + `ON CONFLICT` + `FOR UPDATE` + lease; the second gets `BookingInProgress` or the stored result |
| book after an expired lease | the stale owner's finish | `attempts` | the finish `UPDATE … WHERE attempts = :attempt AND state = 'pending'` writes nothing for the old owner |
| set rates | archive zone | method and zone rows | method first, then the zone `FOR SHARE`; archive takes only the zone |
| consume usage (order 1) | consume usage (order 2) | promotion / coupon counter | conditional `UPDATE used < limit` (re-read after the wait) |
| consume usage | consume usage (same customer) | customer usage row | `INSERT … ON CONFLICT DO UPDATE … WHERE used < limit` |
| consume usage (same `orderRef`) | consume usage | redemption header | `UNIQUE(order_ref)`; the second waits and compares the fingerprint |
| consume usage | update promotion (limit change) / pause / archive / disable coupon | promotion / coupon row | `KEY SHARE` vs `FOR UPDATE`; the recount sees every committed usage, a paused promotion gives `PromotionUnavailable` |
| restore usage | consume usage | redemption header | idempotent by `orderRef`; sales serializes the order before calling the port |
| generate coupons | archive promotion | promotion row | `KEY SHARE` vs `FOR UPDATE` |
| place order | cart edit | cart row | `FOR UPDATE` + version → `CartChanged` |
| place (same cart, same key) | place | receipt | the second waits and returns the same order (C1) |
| place (no key) | place | cart | `FOR UPDATE` → `CartAlreadyPlaced` (C2) |
| initiate | initiate (same order) | order | `FOR UPDATE` + balance → `PaymentExceedsBalance` (C5) |
| record event | record event (same event id) | order + inbox | `ON CONFLICT` → duplicate (C4) |
| record event (captured) | expire / cancel | order | expiry skipped when a payment exists; a capture after cancel → `payment_requires_refund` (C8) |
| reconcile | initiate finish | payment | lease + `attempts` fencing |
| create fulfillment | create fulfillment / cancel lines | order + ledger | `FOR UPDATE` + conditional `UPDATE` → `QuantityExceedsOutstanding` (C6) |
| issue refund | issue refund | order + payment | `FOR UPDATE` + refundable → `RefundExceedsBalance` (C7) |
| refund finish | payment event | order → payment | one lock order |
| expire (worker 1) | expire (worker 2) | order | `SKIP LOCKED` + re-check |
| payment event | cancel order | order row | order `FOR UPDATE`; a paid cancelled order becomes a refund task, never a silent loss |
| change attribute type or config | write value | attribute definition | not possible: `type` and `config` are immutable after `define` (D17) |
| generate / create variant | generate / create variant (one product) | product row | `FOR NO KEY UPDATE`; `UNIQUE` and `ON CONFLICT DO NOTHING` as the backstop |
| create variant / set attributes | archive product | product row | the same lock, then a status check |
| write attribute value | update attribute (status, `filterable`) | attribute definition | writer `FOR SHARE`, update `FOR NO KEY UPDATE` |
| write enum value | add choices | attribute definition | `addChoices` holds the attribute `FOR NO KEY UPDATE`; the writer waits and sees the new choices |
| move category | move category | whole category tree | advisory lock of the tree, then a cycle check by recursive CTE |
| assign categories | archive category | category row | assign `FOR SHARE`, archive `FOR NO KEY UPDATE`; an archived category → `CategoryArchived` |
| publish product | archive channel (core) | — (no cross-package lock) | publish checks the channel status; a race leaves a harmless listing, sales refuse an archived channel |

## Transactions, idempotency, outbox

- `Transactions::run(callable $work, int $lockTimeoutMs = 3000)` runs on the
  Commerz connection, sets `lock_timeout`, and joins an ambient transaction
  when nested through a savepoint. Each failed nested operation rolls back
  its own writes and pending events while the host may continue (D20).
- `OperationReceipts::once(scope, key, fingerprint, work, encode, decode)`
  locks `(scope, key)` inside the transaction: absent → run and store; same
  fingerprint → return the stored result; different fingerprint →
  `IdempotencyConflict`. Table `cmz_operation_receipts`, pruned by age
  (`commerz:operations:prune`, default 30 days).
- `Outbox::record(IntegrationEvent)` requires an open transaction. The relay
  `commerz:outbox:relay` takes batches with `FOR UPDATE SKIP LOCKED`, delivers
  to the event dispatcher and registered `outbox_consumer` contributions, and
  marks delivery. A relay refuses to run inside a transaction on the Commerz
  connection, before claiming messages or calling consumers. Delivery is at least once; consumers deduplicate by
  `event_id`. Domain (Laravel) events are dispatched after commit.
