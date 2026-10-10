No new defects. The round-2 `lockDemand` fix holds for the package call paths, and the three allowed suites passed on `commerz_review3_test`.

`release` and `consume` of one sellable both lock every level the demand still holds, in one `ORDER BY (stock_item_id, location_id)` statement (`DatabaseReservations.php:601`). A later call in the same transaction only re-locks what is still outstanding. `ReservationNotFound` is raised before that lock, and the fulfillment does not catch it, so the outer transaction rolls back. Lazy expiry and the sweeper still take foreign headers `SKIP LOCKED`. Sales keeps receipt, order, child document, stock, then coupon, promotion, customer usage, payment, and refund. A place that locks the promotion before the level still deadlocks with these cancels: on the review database, a 1.5 s `FOR UPDATE` gate, four workers locking the promotion then the level, and four locking the level then the promotion, ended `gate:8` with six `40P01`s.

`scripts/php84` could not refresh `build/php84/bin/php` (permission denied). The runs used the existing PHP 8.4.26 shim there:

- `tests/EndToEnd/Concurrency`: 10 passed, 126 assertions, 32.80 s
- `packages/inventory/tests/Feature/Reservations`: 32 passed, 188 assertions, 13.52 s
- `packages/sales/tests/Feature/Orders`: 45 passed, 248 assertions, 11.34 s

EndToEnd workers use the public facades and ports, map only the named domain exceptions to outcomes, set `deadlock_timeout` before the work, call both reconcile commands from `assertSettled`, and skip only when `pcntl` is missing. The positive control reported `error:40P01` and a deadlock delta of at least 1.

Verdict: pass

Residual risk: E6 still accepts whichever of expiry or fulfillment wins, so one run can be all fulfillments. E4 and E8 depend on the 150 ms and 300 ms head starts and on exact waiter counts of 6 and 8, which assume every contender reaches the gate's queue within 1.5 s and that no other session is waiting in `commerz_review3_test`. A host that releases a demand and then reserves it again in the same transaction, onto a level the first lock did not hold, can still deadlock with another command. No package command does that. A live demand does not gain lines, and the multi-location case uses the same single `lockDemand` statement. The inventory mix covers one location. A `pg_stat_database.deadlocks` flush after the 2 s poll could still hide a deadlock. That did not happen on the positive control in this run.
