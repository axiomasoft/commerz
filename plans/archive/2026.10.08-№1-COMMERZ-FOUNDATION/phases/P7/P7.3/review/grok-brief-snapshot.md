# Read-only review: items P7.1, P7.2, P7.3 of PLAN1 (sales: foundation, carts, checkout placement and orders)

You are an independent reviewer. You may read everything and run read-only commands (tests, phpstan, git). You must not
modify the repository. Inspect, report findings, stop.

Repository: `/home/vostrikov/projects/packages/commerz` — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo.
PHP commands go through `scripts/php84`. Sales tests: `scripts/php84 vendor/bin/pest --testsuite=Sales`
(PostgreSQL at 127.0.0.1:54329; concurrency tests fork with pcntl and commit data).

## Scope under review (COMMITTED: `git diff 5e78878..HEAD -- packages/sales`)

- P7.1 `689c1a8`: config `commerz-sales`, `Common/Internal/{SalesConfig, Clock, Rows, SalesCodec, Lease}`, the four migrations
  (`packages/sales/database/migrations`), provider, `Internal/SalesPlugin`, SPI `Gateways/*` (manual gateway) and
  `Numbering/*` (sequence generator).
- P7.2 `8ee63d5`: `Carts/*`, `Common/Internal/{CartRows, CartRow, CartLineRow, CartLines, CartQuoter, QuotedCart}`, root `Sales`, facade.
- P7.3 `3026393`: `Checkout/*` (`Internal/OrderPlacement`, `Internal/Placement`), `Orders/*` (`Internal/DatabaseOrders`),
  `Common/Internal/{OrderRows, OrderWriter, OrderJournal}`, changes to `CartRows`/`CartLines`/`Sales`, fixtures
  `packages/sales/tests/Fixtures/*`, tests under `packages/sales/tests/{Feature/Checkout, Feature/Orders, Unit/Checkout}`.

Ignore uncommitted changes under `plans/` (owner planning work) except as design input.

## Contract to review against

- `plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/sales-design.json` sections `model`, `layout`, `schema`, `carts`,
  `placement`, `locks`, `api`, `tests`; `knowledge/invariants.json#placement` (global lock order);
  decisions `decisions/D31.json`–`D34.json`, D11, D13, D25, D20 (nested `Transactions::run` = savepoint).
- Item records `phases/P7/P7.1.json`, `P7.2.json`, `P7.3.json` (scope, rules, why/risks, logged deviations in `log.jsonl`).
- Kernel ports used: `packages/core/src/{Operations,Outbox,Ports,Quotes,Stores,Plugins}`.

## What to look for (most important first)

1. ORD-01 / ORD-02 / EXT-01 in `OrderPlacement::place`: a cart becomes at most one order under every interleaving
   (same key, no key, different keys, key reuse with another total), idempotency fingerprint stability across a repeat,
   cart `version` check between the out-of-transaction quote and the `FOR UPDATE` lock, order number inside the
   transaction, savepoint around the insert (DuplicateOrderNumber does not poison a host transaction), every failure
   of steps 4–9 rolling back receipt, order, reservation, promotion usage, cart conversion, journal and outbox together.
2. Lock order and deadlock risk vs `knowledge/invariants#placement`; ports called inside vs outside the transaction
   (Sellables/QuotePipeline/Channels/Stores/CustomerDirectory/Contributions must be before it).
3. Snapshot fidelity: everything `OrderView` returns is decoded from what was written (codec round trip of adjustments,
   order-level shares carrying the line id, delivery taxes, totals, options), and nothing re-reads catalog or prices.
4. Carts: CTX-01 (channel/currency fixed, purchasable checked on add and on placement), version bump semantics, limits,
   keyset pagination cursors, `CartQuoter` as the only quote path, delivery option value.
5. Schema/migrations: CHECK constraints vs design, money/currency columns, FK/no-FK boundaries, the order number
   sequence, down() correctness.
6. SPI/SemVer surface: public types outside `Internal` carry `#[Api]`/`#[Spi]`, no Eloquent/float in public signatures,
   module `Internal` not imported by another module (only `Common/Internal`).
7. Test gaps that hide a real defect (including the concurrency tests C1–C3 and the sole-writer unit test).

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (a failing
Pest test snippet or a command with its output). A finding without a reproduction is at most "plausible". List
questions separately. Finish with a verdict line `Verdict: pass` or `Verdict: fail` and a one-paragraph residual risk.
