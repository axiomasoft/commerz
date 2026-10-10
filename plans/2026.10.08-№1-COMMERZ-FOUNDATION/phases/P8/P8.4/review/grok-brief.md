# Read-only review: item P8.4 of PLAN1 (Cross-package concurrency suite on PostgreSQL)

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository: the kernel refuses
writes outside `vendor/`. KEEP IT FOCUSED: no full suites, no phpstan/pint/rector runs. Other sessions work in the
same checkout: use ONLY the database `commerz_review3_test` (never `commerz_test`, `commerz_review_test`,
`commerz_review2_test`). Run only, one at a time, when you need a reproduction:
`DB_DATABASE=commerz_review3_test scripts/php84 vendor/bin/pest --do-not-cache-result tests/EndToEnd/Concurrency`
(or one file of it; PostgreSQL 127.0.0.1:54329, user/password `commerz`, superuser). A reproduction you cannot write
to disk may be given as a Pest snippet plus the reasoning. Stop after ~25 minutes of work.

Repository: `/home/vostrikov/projects/packages/commerz` — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo.

## Scope (UNCOMMITTED, untracked files)

- `tests/EndToEnd/Concurrency/Support/Outcome.php`: worker wrapper (`SET deadlock_timeout`, expected domain errors by
  class → outcome string, anything else → `error:<SQLSTATE|class>`), SQLSTATE extraction, `pg_stat_database.deadlocks`
  delta with polling, `assertSettled` (no error outcomes, zero deadlock delta, both reconcile commands clean).
- `tests/EndToEnd/Concurrency/Support/Checkout.php`: carts/place/pay helpers used in the parent before forking.
- `tests/EndToEnd/Concurrency/DetectorControlTest.php`: positive control (opposite-order `FOR UPDATE` → 40P01).
- `tests/EndToEnd/Concurrency/PlacementConcurrencyTest.php`: E1 oversell, E2 coupon limit, E3 opposite line order.
- `tests/EndToEnd/Concurrency/LifecycleConcurrencyTest.php`: E4 reserve vs adjust/count, E5 cancel vs capture,
  E6 expiry vs fulfillment, E7 full-refund restore vs consume.
- Uses `tests/EndToEnd/Support/{CommittedDatabase,ShopWorld}.php` (context, other items).

Ignore every other uncommitted change (`plans/`, other `tests/EndToEnd/*` files): not in this review.

## Contract

`plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/assembly-design.json` section `concurrency` (invariant model,
enforcement, bypass, pairs E1–E7, detector, timings, positive control, data, final invariants); item record
`phases/P8/P8.4.json` (rules!); `docs/architecture/invariants.md` (global/sales lock order, interfering operations);
`packages/testkit/src/Concurrency.php`; decisions D20, D21, D27, D29, D32, D33, D35. Logged P8.4 events in
`plans/…/log.jsonl` (`"item":"P8.4"`) are deliberate (E5 single end state, E2 two refusal kinds, E6 via
`Orders::expireDue`, E7 opt-in `restore_on += refunded`, extra Checkout helper).

## What to look for (most important first)

1. Vacuous or weak tests: can a scenario pass without the race actually happening (workers serialized by setup, the
   interesting interleaving impossible, a counter not checked)? Does each Ex really exercise the shared resource named
   in the contract table, and would a real bypass (consume before reserve, level lock out of order, restore without the
   order lock, ledger bypass) turn it red?
2. Detector correctness: SQLSTATE extraction across Laravel's `QueryException`/`PDOException` chain; does any expected
   domain-error mapping swallow a 40P01/55P03 (e.g. a domain exception that wraps a lock timeout); deadlock counter
   polling (stats flush timing on backend exit), the positive control's robustness (can it pass for the wrong reason or
   flake), `SET deadlock_timeout` verification.
3. Real cross-package lock-order defects the suite exposes or should expose (inventory ↔ promotions ↔ sales ↔
   payments/refunds), including E5/E6/E7 end-state assertions that hide a defect behind "either outcome".
4. Data isolation and cleanup: committed data, migrate/truncate, interaction with other test files and `RefreshDatabase`.
5. Anything outside the item's scope.

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (failing
Pest snippet or command output). A finding without a reproduction is at most "plausible". Questions separately.
Finish with `Verdict: pass` or `Verdict: fail` and one paragraph of residual risk.
