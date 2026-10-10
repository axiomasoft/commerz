# P8.4 review — grok-4.7 (effort high, context 500000)

## Round 1 — session 01a1256d-3190-7ea3-9807-de36224c31bc

Verdict: fail. Four findings, all confirmed by the executor:
- High (product): `Fulfillments::create` consumes line by line in one transaction and inventory `consume` locked only
  the consumed sellable's levels, so two fulfillments of the same goods in opposite line order deadlocked (reviewer:
  7 × 40P01; executor: same in the new inventory regression and the new E2E E3 fulfillment test). Fixed in inventory:
  `consume` locks every level the demand still holds, in key order, at its first call; docs/architecture/invariants.md
  updated.
- Medium (test): E7 could pass with every placement serialized before every refund. Now a gate worker holds the
  coupon promotion row so restores and consumes provably queue together (8 waiting sessions asserted).
- Medium (test): E4 accepted 11 for both a legal order and a stale count. Now a gate on the level plus a ledger chain
  check (balanceAfter continuity; a count ends at the counted value).
- Medium (test): no pair of place vs cancel/expiry. New E8: place with the coupon vs cancel of another coupon order on
  the same S level and promotion row, behind the gate.
Residual risk accepted: E6 interleaving is nondeterministic (both branches observed across runs); late stats flush.
Checks after the fix: lint, analyse, refactor, api:check, Inventory (211), Sales (467), EndToEnd (24), concurrency
dir 3/3 runs. Cost 1.38 USD, 57 turns, 1329 s.

## Round 2 (the last allowed) — session 01a12589-c61d-7873-87ae-89fa86f5d142

Verdict: fail. Both findings confirmed by the executor and fixed; no third round (owner budget: two):
- High (product): the wide `consume` lock deadlocked with line-by-line `release` (sales `cancelLines`), and two
  partial cancellations in opposite line order already deadlocked with each other (reviewer: 40P01 executed). Fixed in
  inventory: `release` of one sellable takes the same demand-wide sorted lock as `consume` (shared `lockDemand`).
  Inventory regression now mixes consume and release line by line in opposite orders: red without the fix
  (6 × 40P01, twice), green with it. invariants.md updated.
- Medium (test): E4 accepted a lock-free count whose update was granted before the adjust (a legal-looking 11).
  The count now queues after the adjust (lock waits are FIFO), so the locked code must end at exactly 8; a simulated
  stale count (`counted − 5`) turns E4 red (11 ≠ 8). E8 now requires all 8 contenders behind the gate (was ≥ 2).
Accepted residual risk: E6 interleaving stays nondeterministic; exact waiter counts assume no foreign sessions in the
isolated test database; the 150/300 ms head starts are sleeps (3/3 runs green).
Checks after the fix: lint, analyse, refactor, api:check, Inventory (211), Sales (467), EndToEnd (24), concurrency dir
3/3. Cost 1.20 USD, 45 turns, 1576 s.

## Round 3 (new owner request: 'ревью на grok 4.7') — session 01a125bb-a2d4-78c1-b6b9-7f3f284258b6

Route proven from the session record: model grok-4.7, reasoning_effort high (60 turns), contextWindowTokens 500000.
Scope: final state after both corrections (a658638 + 40f8e3e).

Verdict: pass. No new defects. `lockDemand` holds for consume/release line by line, `ReservationNotFound` precedes the
lock, lazy expiry/sweeper stay `SKIP LOCKED`; plan rules (public API only, class-based outcome mapping,
`deadlock_timeout` first, reconcile after each pair, pcntl-only skip) conform. Its synthetic promotion→level vs
level→promotion repro (6 × 40P01) is a bypass demonstration, not a code path: `Checkout::place` locks stock levels
before usages (invariants.md, Sales lock order), so E8 would catch such an inversion.
Reviewer runs: EndToEnd Concurrency 10 passed; inventory Reservations 32 passed; sales Orders 45 passed.
Accepted residual risk (unchanged): E6 nondeterministic winner; E4/E8 head-start sleeps and exact waiter counts on an
isolated DB; a host that releases and re-reserves onto a new level in one transaction is outside the lock model (no
package command does it); late stats flush after the 2 s poll. Cost 2.12 USD, 60 turns, 1399 s.
