# Read-only review: item P7.6 of PLAN1 (sales: Fulfillments)

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository (you work in a disposable
copy at `/tmp/claude-1000/-home-vostrikov-projects-packages-commerz/c5529ab6-0305-4701-9fe7-2e7be3cb5c17/scratchpad/rv/commerz`, but treat it as read-only). KEEP IT SHORT: no full test suites, no phpstan/pint/rector runs, no
exploratory loops. Budget: read the diff, then run at most these test files, one at a time, each only if you need a
reproduction: `Fulfillments/FulfillmentsTest.php`, `BookFulfillmentTest.php`, `FulfillmentConcurrencyTest.php`
(`scripts/php84 vendor/bin/pest packages/sales/tests/Feature/Fulfillments/<file>`; PostgreSQL 127.0.0.1:54329; the
book and concurrency tests commit data and truncate sales tables afterwards). Stop after ~15 minutes of work.

Repository: `/tmp/claude-1000/-home-vostrikov-projects-packages-commerz/c5529ab6-0305-4701-9fe7-2e7be3cb5c17/scratchpad/rv/commerz` — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo. PHP commands via `scripts/php84`.

## Scope (COMMITTED: `git diff a8447fc..eac1ee4 -- packages/sales`)

- `packages/sales/src/Fulfillments/**` (Contracts, Data, Enums, Events, Exceptions, Internal/{DatabaseFulfillments,
  FulfillmentRows, FulfillmentBooking}), `Sales::fulfillments()`, provider binding, facade.
- Tests: `tests/Feature/Fulfillments/*`, `tests/Unit/Fulfillments/FulfillmentGatesTest.php`, the new case in
  `tests/Feature/Orders/CancelLinesTest.php`.

Ignore uncommitted changes under `plans/` (owner planning work) except as design input.

## Contract

`plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/sales-design.json` sections `fulfillment`, `locks`, `model`, `api`,
`tests` (C6); decisions D14, D29, D30, D22; item record `phases/P7/P7.6.json`. Logged deviations of P7.6 in `log.jsonl`
(`"item":"P7.6"`) are deliberate — challenge them only if they break an invariant. Reference: `packages/shipping/src/Bookings`.

## What to look for (most important first)

1. ORD-03 / stock: units fulfilled once; ledger moves before consume; consume only for Physical lines with the
   `locationCode`; any refusal rolls everything back; no double consume on idempotent replay; races with cancel/cancelLines/expiry.
2. Lock order (order → fulfillment → stock) and deadlock risk; every write under `OrderLocks::lock`.
3. `book`: the carrier is never called inside a DB transaction nor under a lock; a lost answer / repeat is safe through the
   reference `fulfillment:{id}`; a repeat or a race with `ship` never overwrites tracking; parcel weight and value are right.
4. Derived order states after create (completed only when paid), outbox `sales.fulfillment_shipped`, events, journal consistency.
5. Public surface/SemVer: #[Api] on public types, no Eloquent/float, no import of another module's Internal, error codes vs `sales-design#api`.
6. Test gaps that hide a real defect.

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (failing Pest
snippet or command output). A finding without a reproduction is at most "plausible". Questions separately. Finish with
`Verdict: pass` or `Verdict: fail` and one paragraph of residual risk.
