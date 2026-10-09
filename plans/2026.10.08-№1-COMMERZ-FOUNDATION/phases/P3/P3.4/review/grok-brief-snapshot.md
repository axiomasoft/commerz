# Read-only review: phase P3 (inventory) of PLAN1, items P3.1–P3.4

You are an independent reviewer. You may read everything and run commands, including the test suite. Do not edit
any file: inspect, report findings, stop.

Repository: `/tmp/claude-1000/-home-vostrikov-projects-packages-commerz/895ed1a8-5b89-4b1c-b697-64721bb67415/scratchpad/review-snapshot` (an isolated snapshot of the owner's repository; it is yours to read and test) — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo
(headless commerce). PHP commands go through `scripts/php84` (the default `php` is 8.3).

## Scope under review

- P3.1 — commit `705ce5a`: kernel port extension `Commerz\Ports\Stock` (`StockReturns`, `RestockStock`,
  `RestockLine`, `ConsumeReservation::$locationCode`), `UnlimitedStock`, testkit `FakeStock` (decision D22).
- P3.2 — commit `0ebfec6`: `packages/inventory` foundation: config, Common, schema migrations, provider, the
  `allocation_strategy` SPI with `priority` and `single_location` strategies.
- P3.3 — commit `b915b1e`: Locations, stock items with sellable links (kits), channel sources, `Inventory` root.
- P3.4 — UNCOMMITTED in the working tree (`git status`; new files untracked under `packages/inventory/src/Stock`,
  `src/Movements`, `src/Common/Internal/{StockLedger,LevelRow}.php`, `tests/Feature/Stock`,
  `tests/Unit/LedgerBoundaryTest.php`; modified `LevelQueries`, `Inventory`, facade, provider,
  `UntrackedStockItem`): `StockLedger` (only writer of levels and movements), `Inventory::stock()` (receive,
  adjust, count with CAS `expectedOnHand`, safety stock, level, list), `Inventory::movements()`, outbox
  `inventory.level_changed`, events `StockLevelChanged`/`StockShortfall`, `StockReturns` implementation (restock).

Diff of the committed part: `git diff e92e2ca b915b1e -- packages`. Working-tree part: `git diff -- packages` plus
the untracked files listed by `git status --short packages`.

Ignore uncommitted changes under `plans/` (owner planning work) except as design input.

## Design inputs (the contract to review against)

- `plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/inventory-design.json` — model, schema, allocation, ledger,
  reservations, concurrency/lock hierarchy, API (sections `model`, `schema`, `allocation`, `ledger`, `concurrency`,
  `api`, `port-changes`).
- Decisions `plans/2026.10.08-№1-COMMERZ-FOUNDATION/decisions/D21.json`, `D22.json`, `D20.json`.
- Item records `plans/2026.10.08-№1-COMMERZ-FOUNDATION/phases/P3/P3.{1,2,3,4}.json` (scope, rules) and the logged
  deviations in `plans/2026.10.08-№1-COMMERZ-FOUNDATION/log.jsonl` (JSON lines with `"phase":"P3"`) — a logged
  deviation is an intentional decision; challenge it only if it is actually wrong.
- `docs/architecture/*.md`, `CLAUDE.md` (package boundaries: feature packages depend only on the core's public
  contracts and write only their own tables).

## What to look for (most important first)

1. Correctness and concurrency on PostgreSQL READ COMMITTED: lock order (entity rows → levels ordered by
   `(stock_item_id, location_id)` `FOR NO KEY UPDATE` in one statement), deadlock or lost-update windows, archive vs
   stock-change races, read-then-write of quantities, `ON CONFLICT` paths, savepoint behaviour of nested
   `Transactions::run` (D20), idempotency through `OperationReceipts::once`.
2. Invariants INV-02/03 (on-hand/reserved/safety never negative; every on-hand change is exactly one movement with
   the right `balance_after`), int64 overflow (no float), events after commit and outbox in the same transaction.
3. Public API / SemVer surface: every public type outside `Internal` carries `#[Api]`/`#[Spi]`; no Eloquent,
   Builder, Collection or float in public signatures; error codes; consistency with the design and the blueprint.
4. Allocation strategies (P3.2): kits, backorder rules, all-or-nothing, determinism.
5. Test gaps that leave a real risk unproven.

Skip style nits that Pint/PHPStan/Rector already enforce.

## Evidence

For every finding give a reproduction: a failing test (as code you would add), a command and its output, or a
precise interleaving of two transactions with the statements in order. A finding without a reproduction is at most
"plausible". PostgreSQL 17 is reachable as configured in `phpunit.xml`; run tests with
`scripts/php84 vendor/bin/pest <path>` (e.g. `packages/inventory/tests`). Say when a claim is unverified.

## Output

End your answer with exactly one fenced `json` block:

```json
{
  "verdict": "pass | pass_with_findings | fail",
  "findings": [
    {
      "id": "F1",
      "severity": "critical | high | medium | low",
      "item": "P3.1 | P3.2 | P3.3 | P3.4",
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
