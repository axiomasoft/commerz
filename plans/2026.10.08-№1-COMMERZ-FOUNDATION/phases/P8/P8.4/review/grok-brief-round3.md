# Read-only review (round 3, a new owner request): item P8.4 of PLAN1 — final state after two corrections

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository (the kernel refuses
writes outside `vendor/`). No phpstan/pint/rector, no full package suites. Other sessions work in the same checkout:
use ONLY the database `commerz_review3_test`. You may run, one at a time:
`DB_DATABASE=commerz_review3_test scripts/php84 vendor/bin/pest --do-not-cache-result tests/EndToEnd/Concurrency`
`DB_DATABASE=commerz_review3_test scripts/php84 vendor/bin/pest --do-not-cache-result packages/inventory/tests/Feature/Reservations`
`DB_DATABASE=commerz_review3_test scripts/php84 vendor/bin/pest --do-not-cache-result packages/sales/tests/Feature/Orders`
(PostgreSQL 127.0.0.1:54329, user/password `commerz`, superuser). A throwaway repro script may live under `vendor/`
only. Stop after ~25 minutes of work.

Repository: `/home/vostrikov/projects/packages/commerz`. The item is the cross-package concurrency suite
(`tests/EndToEnd/Concurrency/**`, pairs E1–E8, SQLSTATE/deadlock detector, positive control) plus lock-order fixes
in the owning packages. Commits: `a658638` (suite + round-1 correction), `40f8e3e` (round-2 correction). Earlier
rounds and the executor's verdict: `plans/2026.10.08-№1-COMMERZ-FOUNDATION/phases/P8/P8.4/review/`
(`grok-answer.md`, `grok-answer-round2.md`, `verdict.md`). Do not re-report findings already fixed there unless the
fix is incomplete.

## Round-2 correction (`git show 40f8e3e`)

1. Product: inventory `DatabaseReservations::release` of one sellable now takes the same demand-wide sorted lock as
   `consume` (shared `lockDemand`: every outstanding level of the demand plus the lines at hand, in global key
   order). Regression mixes consume and release line by line in opposite orders
   (`packages/inventory/tests/Feature/Reservations/ReservationConcurrencyTest.php`). Docs: `docs/architecture/invariants.md`.
2. Tests: E4 count now queues after the adjust (FIFO lock waits), so it must end at exactly 8; E8 requires all 8
   contenders behind the gate.

## What to look for

- Remaining deadlock cycles across packages on real code: every inventory entry point that locks levels after
  another lock in the same transaction (reserve/re-reserve on cart change, release on cancel/expiry, consume on
  fulfillment, restock on refund/return, count, adjust, transfers, sweeper) combined with sales/promotions/payments
  locks taken earlier in the caller's transaction (order header, promotion row, payment row). Check against the
  global order in `docs/architecture/invariants.md` (Global lock order, Sales lock order, Interfering operations).
- `lockDemand` correctness: a release/consume whose lines are no longer outstanding, a demand spanning several
  locations, `ReservationNotFound` and lazy-expiry paths, lines added to the demand later in the same transaction.
- Test strength: would each named bypass (removed lock, stale read, missing restore) turn a pair red? Vacuous
  passes, flaky timing (`lock_timeout` 3 s, 150/300 ms head starts, exact waiter counts), detector false negatives.
- Plan conformance: rules in `plan.py show 2026.10.08-№1-COMMERZ-FOUNDATION P8.4` (only public API in EndToEnd
  tests, worker catches only expected domain exceptions by class, `SET deadlock_timeout` first, reconcile commands
  exit 0 after each pair, skip only without pcntl).

## Output

For every finding: severity, file:line, the defect in one sentence, and a REPRODUCTION (executed failing test or
command output). A finding without one is at most "plausible". Finish with `Verdict: pass` or `Verdict: fail` and one
paragraph of residual risk.
