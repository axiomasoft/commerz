# Read-only review: item P3.5 of PLAN1 (inventory reservations and availability)

You are an independent reviewer. You may read everything and run commands, but you must not modify the repository.
Inspect, report findings, stop.

Repository: the current working directory — a copy of the Commerz monorepo (Laravel 13 / PHP 8.4 / PostgreSQL ≥16,
headless commerce packages). PHP commands go through `scripts/php84` (the default `php` is 8.3).

## Scope under review

P3.5 is UNCOMMITTED in the working tree (base commit `dbf79b3`). Files:

- new: `packages/inventory/src/Reservations/**` (contract `Reservations extends StockReservations`, data, enums,
  events, `ReservationConflict`, internals `DatabaseReservations`, `ReservationStore`, `LazyExpiry`,
  `DemandResolver`, `DemandLine`, `LineRow`), `packages/inventory/src/Availability/Internal/DatabaseAvailability.php`,
  `packages/inventory/src/Internal/Console/ExpireReservationsCommand.php`,
  `packages/inventory/tests/Feature/Reservations/{ReservationsTest,ReservationConcurrencyTest,Helpers}.php`,
  `packages/inventory/tests/Feature/Availability/AvailabilityTest.php`;
- modified: `packages/inventory/src/Common/Internal/LevelQueries.php` (`sourceStock`), `Inventory.php`,
  `Facades/Inventory.php`, `InventoryServiceProvider.php`.

See `git status --short packages` and `git diff -- packages`. Code from P3.1–P3.4 (`StockLedger`, allocation
strategies, schema) is context; report a defect there only when P3.5 depends on it.

Ignore uncommitted changes under `plans/` except as design input.

## Design inputs (the contract to review against)

- `plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/inventory-design.json` — sections `model`, `allocation`,
  `ledger`, `reservations`, `concurrency`, `api`.
- Item record `plans/2026.10.08-№1-COMMERZ-FOUNDATION/phases/P3/P3.5.json` (scope, rules, guidance) and its logged
  deviations in `plans/2026.10.08-№1-COMMERZ-FOUNDATION/log.jsonl` (lines with `"item":"P3.5"`) — a logged
  deviation is an intentional decision; challenge it only if it is actually wrong.
- Decisions `decisions/D10.json`, `D20.json`, `D21.json`, `D22.json` under the plan directory.
- Kernel port: `packages/core/src/Ports/Stock/**`; reference semantics `packages/testkit/src/Fakes/FakeStock.php`.

## What to look for (most important first)

1. Concurrency on PostgreSQL READ COMMITTED: the lock hierarchy (demand header → items/locations `FOR SHARE` →
   levels in one ordered `FOR NO KEY UPDATE` → reservation lines by id; foreign headers only `SKIP LOCKED`),
   deadlock cycles (reserve vs reserve, reserve vs consume/release/expire, lazy expiry vs sweeper, archive vs
   reserve), oversell (allocation from unlocked reads), double release/consume, the `INSERT … ON CONFLICT DO
   NOTHING` repeat path, savepoint behaviour of nested `Transactions::run` (D20).
2. Invariants INV-02 (no tracked over-reserve), INV-04 (`units = consumed + released + expired + outstanding`,
   `reserved(level) = Σ outstanding × qpu`), INV-05 (one set of lines per demand), int64 overflow.
3. Port semantics vs `StockReservations`/`StockAvailability` docblocks and `FakeStock`: all-or-nothing, repeat,
   release no-op, consume partial / location / `ReservationNotFound`, availability null/0 semantics, one snapshot.
4. Public API / SemVer: `#[Api]` on public types, no Eloquent/Builder/Collection/float in public signatures, error
   codes.
5. Test gaps that leave a real risk unproven.

Skip style nits that Pint/PHPStan/Rector enforce.

## Evidence

For every finding give a reproduction: a failing test (as code you would add), a command and its output, or a
precise interleaving of two transactions with the statements in order. A finding without a reproduction is at most
"plausible". PostgreSQL for tests may be reachable at 127.0.0.1:54329 (see phpunit.xml); if not, reason from code
and SQL and say the claim is unverified. Never write to a database outside the test suite.

## Output

End your answer with exactly one fenced `json` block:

```json
{
  "verdict": "pass | pass_with_findings | fail",
  "findings": [
    {
      "id": "F1",
      "severity": "critical | high | medium | low",
      "file": "repo-relative path",
      "line": 0,
      "summary": "one sentence",
      "failure_scenario": "concrete inputs/interleaving -> wrong result",
      "reproduction": "test code, command+output, or interleaving",
      "confidence": "confirmed | plausible"
    }
  ]
}
```
