# Invariants, transactions and lock order

The registry of business invariants Commerz guarantees, who enforces each one,
which bypass it closes and how it is tested. Every invariant has one owner and
one enforcement point; adding a write path that skips it is a defect.

## Invariant registry

| ID | Invariant | Owner / enforcement point | Bypass closed | Test |
|---|---|---|---|---|
| INV-01 | A keyed command applies at most once in its scope; a retry with another payload is a conflict | `OperationReceipts::once` (core) + the owner's natural unique key | calling an `Internal` handler directly (arch gate: `Internal` is closed) | retry, concurrent retry, different payload |
| INV-02 | `0 ≤ reserved ≤ on_hand` per level (unless `allow_negative`) | inventory: conditional `UPDATE … SET reserved = reserved + :q WHERE id = :id AND on_hand - reserved >= :q` + `CHECK (reserved >= 0)` | read-then-write in PHP (forbidden; only conditional `UPDATE` or `FOR UPDATE`) | 8 processes × last unit |
| INV-03 | Every `on_hand` change is exactly one `cmz_stock_movements` row in the same transaction with a consistent `balance_after` | inventory `StockLedger`, the only writer of levels | writing a level without a movement (arch: only `Internal\StockLedger` writes `cmz_stock_levels`) | reconcile reports 0 differences after load |
| INV-04 | Reservation: `quantity = consumed + released + outstanding`; consume, release and expire never free a unit twice | inventory reservation state machine, reservation row `FOR UPDATE` | expiry worker racing consume | expire vs consume race |
| CAT-01 | An axis combination is unique within a product | catalog: `UNIQUE(product_id, option_signature)` over normalized value IDs | generating variants from two requests | concurrent generate |
| CAT-02 | An attribute value passed its type and domain for every writer | catalog `AttributeWriter`, the only path; imports go through the contract | direct JSONB `UPDATE` | import with an invalid value |
| CAT-03 | Facet `red AND M` means one variant with both values | projection `cmz_attribute_index` with `variant_id` | filtering by product instead of variant | red/S + blue/M → 0 results |
| PRC-01 | Validity windows of one price (list, sellable, group, min quantity) never overlap | pricing: `EXCLUDE USING gist` | two parallel `SetPrice` | concurrent insert |
| PRM-01 | Promotion, coupon and per-customer limits and budgets are never exceeded concurrently | promotions `PromotionUsage::consume`: conditional counter `UPDATE`s in fixed order | consuming when a code is entered into a cart (forbidden) | limit 10 with 40 concurrent orders |
| PRM-02 | Discounts allocated to lines sum exactly to the total discount | core `MoneyAllocator` | rounding each line separately | 3 lines × 1/3 |
| ORD-01 | Retrying `PlaceOrder` never creates a second order | sales: `UNIQUE(cart_id)` on orders + `OperationReceipts` | client timeout and retry | concurrent double submit |
| ORD-02 | An order keeps the accepted amounts and snapshots; repricing live data never changes it | sales: snapshot lines, no FK to catalog | refreshing an order from the catalog | change the price after placement |
| ORD-03 | Per line `ordered = cancelled + fulfilled + outstanding` | sales `OrderLineLedger` | shipping more than ordered | partial shipment + cancel |
| PAY-01 | A provider event applies at most once | sales: `UNIQUE(gateway, external_event_id)` | repeated webhook | double webhook |
| PAY-02 | A provider network call never runs inside a database transaction; an unknown outcome is `pending_reconciliation`, not `failed` | sales payment workflow | calling a gateway inside `Transactions::run` | timeout after authorization |
| REF-01 | Refunding money never returns goods to stock automatically | sales return receipt with explicit `restock` | `available += returned` on refund | refund without receiving |
| CTX-01 | A cart or order never changes channel or currency; references from another channel are rejected | sales + `Sellables` (`purchasable` in the channel) | changing `channel_id` | adding an unpublished variant |
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

## Interfering operations

| Operation A | Operation B | Shared resource | Protection |
|---|---|---|---|
| reserve (order 1) | reserve (order 2) | stock level | conditional `UPDATE` on the level row |
| reserve | adjust / count | stock level | both through `StockLedger` with level `FOR UPDATE` |
| consume (shipment) | expire (worker) | reservation row | reservation `FOR UPDATE` + state check |
| transfer dispatch | reserve at the source | source stock level | the same lock order |
| consume usage (order 1) | consume usage (order 2) | coupon/promotion counter | conditional `UPDATE used < limit` |
| restore usage | consume usage | counter | restore is idempotent by `orderRef` (`cmz_promotion_usages` rows) |
| place order | cart edit | cart row | `FOR UPDATE` + version |
| payment event | cancel order | order row | order `FOR UPDATE`; a paid cancelled order becomes a refund task, never a silent loss |
| change attribute type | write value | attribute definition | type change is forbidden while values exist (v0) |

## Transactions, idempotency, outbox

- `Transactions::run(callable $work, int $lockTimeoutMs = 3000)` runs on the
  Commerz connection, sets `lock_timeout`, and joins an ambient transaction
  when nested.
- `OperationReceipts::once(scope, key, fingerprint, work, encode, decode)`
  locks `(scope, key)` inside the transaction: absent → run and store; same
  fingerprint → return the stored result; different fingerprint →
  `IdempotencyConflict`. Table `cmz_operation_receipts`, pruned by age
  (`commerz:operations:prune`, default 30 days).
- `Outbox::record(IntegrationEvent)` requires an open transaction. The relay
  `commerz:outbox:relay` takes batches with `FOR UPDATE SKIP LOCKED`, delivers
  to the event dispatcher and registered `outbox_consumer` contributions, and
  marks delivery. Delivery is at least once; consumers deduplicate by
  `event_id`. Domain (Laravel) events are dispatched after commit.
