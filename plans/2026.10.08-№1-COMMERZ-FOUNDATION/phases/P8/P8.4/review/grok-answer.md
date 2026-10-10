The concurrency suite stays green while two fulfillments of the same two goods in opposite line orders deadlock on PostgreSQL. E3 only places those carts.

## Findings

**High** — `packages/sales/src/Fulfillments/Internal/DatabaseFulfillments.php:197` (missed by `tests/EndToEnd/Concurrency/PlacementConcurrencyTest.php:88`). `Fulfillments::create` holds one transaction and calls `StockReservations::consume` once per line, in order-line id order. Each consume locks only that line's stock level, and the nested savepoint does not release the lock, so the levels stay locked in cart-insertion order. Opposite carts lock TEE-S then MUG versus MUG then TEE-S. `StockLedger::lock` sorts a single reserve, which is all E3 does, so E3 stays green.

REPRODUCTION (database `commerz_review3_test`, 8 placed orders, even carts `[TEE-S, MUG]`, odd carts `[MUG, TEE-S]`, then 8 concurrent `Fulfillments::create` of both lines):

```text
outcomes: ["error:40P01","error:40P01","error:40P01","error:40P01","error:40P01","error:40P01","error:40P01","ok"]
pg_stat_database.deadlocks delta: 7
line ids: even orders TEE-S then MUG, odd orders MUG then TEE-S
```

**Medium** — `tests/EndToEnd/Concurrency/LifecycleConcurrencyTest.php:168`. E7 starts with 4 of the coupon promotion's 5 uses still available (`ShopWorld` `UsageLimits(total: 5)`), and the test accepts `placed >= 1` with `usage.orders === placed`. Every new place can finish before every refund, and the assertions still pass. The coupon row is never required to be held by restore and consume at the same time.

REPRODUCTION: the E7 setup, then all four new places, then all four refunds, on `commerz_review3_test`:

```text
outcomes: ["ok","quote_changed","quote_changed","quote_changed"]
placed: 1
usage.orders: 1
refunds: succeeded × 4
commerz:inventory:reconcile: 0, "INFO  Stock is consistent."
commerz:promotions:reconcile: 0, "INFO  Promotions are consistent."
```

Those are the same postconditions as `assertSettled` plus the E7 expectations.

**Medium** — `tests/EndToEnd/Concurrency/LifecycleConcurrencyTest.php:71`. E4 allows `on_hand` of 8 or 11. `StockLedger::update` writes `on_hand = on_hand + ?` with no version predicate (`packages/inventory/src/Common/Internal/StockLedger.php:286`). Count computes its delta in PHP from the level it read (`DatabaseStockLevels.php:102`). If adjust and count both read the starting 5, adjust adds 3 and count adds `8 - 5`, `on_hand` ends at 11 and the movement sum still matches, so reconcile stays clean. That is the outcome the test already treats as "the adjustment followed the count." Removing `StockLedger::lock` from this pair leaves E4 green.

REPRODUCTION (snippet, from those statements; starting `on_hand` 5, count target 8, adjust +3):

```sql
-- both read 5, then:
update cmz_stock_levels set on_hand = on_hand + 3 where id = :level;          -- adjust
update cmz_stock_levels set on_hand = on_hand + (8 - 5) where id = :level;    -- stale count
-- on_hand = 11, journal deltas +3 and +3, reconcile matches
```

**Medium** — `tests/EndToEnd/Concurrency/LifecycleConcurrencyTest.php:117`. Nothing in the suite runs `Checkout::place` against cancel or expiry. Place locks stock levels and then the promotion; cancel and expiry lock the order, then release stock, then restore the promotion. Swapping place to consume before reserve deadlocks with that pair on one stock level and one limited promotion. E5 races cancel with capture, and E6 races expiry with fulfillment; both take the same order row first, so the later stock and promotion locks never overlap. E7's refund restore does not lock stock. A consume-before-reserve regression stays green.

REPRODUCTION (snippet of the missing pair; not executed, because it needs a production lock swap):

```php
// worker 0: Checkout::place of a new S cart with SALE100
// worker 1: Sales::orders()->cancel of another placed S order that used SALE100
// inverted place (promotion row, then stock level) + cancel (stock level, then promotion row) => error:40P01
```

## Detector and isolation

`Outcome::sqlState` returns `40P01` for a `QueryException` and for Laravel's nested `DeadlockException` (code `0`, `errorInfo` null, previous `QueryException`). `SHOW deadlock_timeout` after `SET deadlock_timeout = '100ms'` is `100ms`. In the fulfillment run the deadlock counter moved by 7, matching the seven `error:40P01` outcomes. These tests use `CommittedDatabase` (migrate, then truncate every `cmz_` table) and do not use `RefreshDatabase`. The runs above asserted `current_database() = commerz_review3_test` before migrating.

No questions.

Verdict: fail

Residual risk: E1, E2, E3, and E5 check the invariants they name (exact stock and coupon totals, opposite-line placement, and the single cancel-plus-capture end state). E6 checks that expiry and fulfillment do not both win an order, and one run can still pass with fulfillment winning every order. A late flush of `pg_stat_database.deadlocks` after 600 ms of an unchanged counter could still hide a deadlock; that did not happen on the deadlock that was produced here. `scripts/php84` could not replace `build/php84/bin/php` (permission denied); the runs used the existing PHP 8.4.26 shim on `commerz_review3_test` only.
