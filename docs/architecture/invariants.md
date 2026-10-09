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
| PRM-01 | Promotion, coupon and per-customer limits and budgets are never exceeded concurrently | promotions `PromotionUsage::consume`: conditional counter `UPDATE`s in fixed order | consuming when a code is entered into a cart (forbidden) | limit 10 with 40 concurrent orders |
| PRM-02 | Discounts allocated to lines sum exactly to the total discount | core `MoneyAllocator` | rounding each line separately | 3 lines × 1/3 |
| ORD-01 | Retrying `PlaceOrder` never creates a second order | sales: `UNIQUE(cart_id)` on orders + `OperationReceipts` | client timeout and retry | concurrent double submit |
| ORD-02 | An order keeps the accepted amounts and snapshots; repricing live data never changes it | sales: snapshot lines, no FK to catalog | refreshing an order from the catalog | change the price after placement |
| ORD-03 | Per line `ordered = cancelled + fulfilled + outstanding` | sales `OrderLineLedger` | shipping more than ordered | partial shipment + cancel |
| PAY-01 | A provider event applies at most once | sales: `UNIQUE(gateway, external_event_id)` | repeated webhook | double webhook |
| PAY-02 | A provider network call never runs inside a database transaction; an unknown outcome is `pending_reconciliation`, not `failed` | sales payment workflow | calling a gateway inside `Transactions::run` | timeout after authorization |
| REF-01 | Refunding money never returns goods to stock automatically | sales return receipt with explicit `restock` | `available += returned` on refund | refund without receiving |
| CTX-01 | A cart or order never changes channel or currency; references from another channel are rejected | sales + `Sellables` (`purchasable` in the channel); the catalog's `variant` provider reports `not_listed` / `not_available_yet` / `no_longer_available` per channel and `context.at` | changing `channel_id`; adding an unpublished, archived or out-of-window variant | adding an unpublished variant; every reason in `VariantSellablesTest` |
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

**cart/order → stock levels (by `stock_item_id`, `location_id`) → coupons →
promotions → customer usages → payments**

Any new operation must follow it; a violation is a defect. `lock_timeout` is
3 s. On `LockNotAvailable` or deadlock, only the whole transaction is retried,
at most 3 times with jitter.

Cancelling or expiring an unpaid order runs in one `Transactions::run`: order
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
against another command. Lazy expiry and the sweeper take foreign headers only
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

## Interfering operations

| Operation A | Operation B | Shared resource | Protection |
|---|---|---|---|
| reserve (order 1) | reserve (order 2) | stock levels | ordered `FOR NO KEY UPDATE` + conditional `UPDATE` |
| reserve (demand X) | reserve (demand X) | demand header | `UNIQUE(demand)`; the second waits and receives the repeat |
| reserve | adjust / count / receive | stock level | both through `StockLedger::lock` |
| consume (shipment) | expire (worker) | demand header and lines | header `FOR NO KEY UPDATE`; the sweeper takes it `SKIP LOCKED` and re-checks |
| count with `expectedOnHand` | reserve / consume | `on_hand` | the compare-and-set runs under the level lock |
| transfer create / dispatch | reserve at the source | source stock level | the same lock order; the draft holds its stock as a reservation |
| consume usage (order 1) | consume usage (order 2) | coupon/promotion counter | conditional `UPDATE used < limit` |
| restore usage | consume usage | counter | restore is idempotent by `orderRef` (`cmz_promotion_usages` rows) |
| place order | cart edit | cart row | `FOR UPDATE` + version |
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
