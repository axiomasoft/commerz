The wide `consume` lock fixes two fulfillments and opens a deadlock with line-by-line `release`. The new consume test and the end-to-end concurrency suite passed on `commerz_review3_test`.

## Findings

**High** — `packages/inventory/src/Reservations/Internal/DatabaseReservations.php:85` (wide lock at line 159; caller `packages/sales/src/Orders/Internal/OrderCancellation.php:95`). `release` locks only the sellable of that call, and `cancelLines` issues one `release` per line in order-line id order, so a partial cancel deadlocks with `consume`, which on its first call locks every outstanding level in `(stock_item_id, location_id)` order.

REPRODUCTION (executed on `commerz_review3_test`): item `repro-a` `01a1259b-958b-7509-abca-fcae74f898a4` created before `repro-b` `01a1259b-959f-727f-9ce1-6aa2a9c88b96`; both demands hold one of each. Worker 0 releases `repro-b`, waits 500 ms, then releases `repro-a`. Worker 1 waits 150 ms and consumes only `repro-a`.

```text
outcomes: ["release: QueryException 40P01 deadlock detected, while locking tuple in cmz_stock_levels, SQL … in ((01a1259b-958b-7509-abca-fcae74f898a4, 01a1259b-9579-7272-9540-bd66f6b04aa9)) … for no key update of lv", "consume:ok"]
pg_stat_database.deadlocks delta: 1
```

The victim statement is the second `release`, locking only the earlier item. `consume` of that earlier sellable was already holding it and waiting for `repro-b`. `cancelLines` takes this path whenever the cancel leaves units or the order already has a fulfillment (`fulfilled() > 0` skips `close()`). A whole-order `release` (no sellable) locks every outstanding level in one sorted statement. With the shop catalog (TEE-S created before MUG), a cart that adds MUG and then TEE has that reversed line-id order.

**Medium** — `tests/EndToEnd/Concurrency/LifecycleConcurrencyTest.php:91` (also line 98; gate `tests/EndToEnd/Concurrency/Support/Outcome.php:67`; count read `packages/inventory/src/Stock/Internal/DatabaseStockLevels.php:107`). E4 stays green for a lock-free count whose update is granted before the adjust: the count movement ends at 8 and `on_hand` ends at 11, and `waiting === 6` still holds because a plain `UPDATE` waits on the gate's `FOR UPDATE`.

REPRODUCTION (statement order the assertions accept; the suite was not patched):

```text
on_hand = 5
-- during the gate, a SELECT sees 5; the later UPDATE waits on FOR UPDATE
update cmz_stock_levels set on_hand = on_hand + (8 - 5) …  -- count, balance_after 8
update cmz_stock_levels set on_hand = on_hand + 3 …        -- adjust, balance_after 11
-- chain holds, count ends at 8, on_hand is 11, reserved is 4
```

The other grant order leaves the count at `balance_after` 11 and fails the test. Removing `StockLedger::lock` is red only when adjust wins that queue.

E7's bypass does turn red: a refund blocks only on the promotion row, so `waiting === 8` fails when placements never meet it. E3 and the inventory opposite-line consume test passed (2.36 s, no `40P01`).

Verdict: fail

Kits, backorders, explicit locations, and a second `consume` in the same transaction stay inside the first sorted lock, because every outstanding level is taken there; `ReservationNotFound` is raised before that lock, and a fulfillment does not catch it. Count, restock, reserve, and transfer creation take items and locations `FOR SHARE` and then levels in the same key order, so they wait on a fulfillment instead of cycling with it. The sweeper still takes foreign headers `SKIP LOCKED` and then levels in that same order; the wider hold only delays it until commit. Two partial cancels whose line-id orders differ still deadlock with each other. E8 treats two waiters as enough, so two cancels blocked on the level satisfy the gate without a placement waiting on the promotion row. One run of the end-to-end suite passed (10 tests); the 150 ms head start is a sleep, and an extra waiter in `commerz_review3_test` would move the exact counts of 6 and 8.
