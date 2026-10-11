# Read-only re-review (round 2, the last): item P8.4 of PLAN1 — the owning correction of round 1

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository (the kernel refuses
writes outside `vendor/`). KEEP IT FOCUSED on the correction; no full suites, no phpstan/pint/rector. Other sessions
work in the same checkout: use ONLY the database `commerz_review3_test`. You may run, one at a time:
`DB_DATABASE=commerz_review3_test scripts/php84 vendor/bin/pest --do-not-cache-result tests/EndToEnd/Concurrency`
`DB_DATABASE=commerz_review3_test scripts/php84 vendor/bin/pest --do-not-cache-result packages/inventory/tests/Feature/Reservations`
(PostgreSQL 127.0.0.1:54329, user/password `commerz`, superuser). Stop after ~20 minutes of work.

Repository: `/home/vostrikov/projects/packages/commerz`. The correction is commit `a658638` (`git show a658638`; the suite
itself was first committed there). Round 1 (your earlier findings) and the executor's verdict:
`plans/2026.10.08-№1-COMMERZ-FOUNDATION/phases/P8/P8.4/review/{grok-answer.md,verdict.md}`.

## The correction

1. Product (high finding): inventory `DatabaseReservations::consume` now locks every level the demand still holds
   (outstanding lines), in key order, at its first call, not only the consumed sellable's; later calls in the same
   transaction find them held. Regression: `packages/inventory/tests/Feature/Reservations/ReservationConcurrencyTest.php`
   ('does not deadlock documents that consume the same items in opposite line order'); E2E: E3 fulfillment test in
   `tests/EndToEnd/Concurrency/LifecycleConcurrencyTest.php`. Docs: `docs/architecture/invariants.md` inventory lock order.
2. Tests (medium findings): `Outcome::gate()` — a worker holds a row `FOR UPDATE` while the others queue, and reports the
   waiting sessions from `pg_locks`; used by E4 (level), E7 and the new E8 (coupon promotion row). E4 also checks the
   ledger chain (`balanceAfter` continuity, a count ends at the counted value).

## What to look for

- Is the product fix complete and safe: other multi-line consumers of one transaction (fulfillment with explicit
  location, kits, backorder allocations, partial fulfillment, `consume` of lines at different locations), lazy expiry or
  the sweeper interacting with the wider lock set, `ReservationNotFound` paths, lock set vs global order (stock items /
  locations FOR SHARE before levels), any new deadlock with transfers, reserve, release, restock or count.
- Do the gates make the races certain without making the tests flaky (timing vs `lock_timeout` 3 s, `Concurrency::run`
  30 s/60 s, the 150 ms contender delay, the exact waiter counts asserted), and would each named bypass now turn red?
- Anything the correction broke elsewhere.

## Output

For every finding: severity, file:line, the defect in one sentence, and a REPRODUCTION. A finding without one is at
most "plausible". Finish with `Verdict: pass` or `Verdict: fail` and one paragraph of residual risk.
