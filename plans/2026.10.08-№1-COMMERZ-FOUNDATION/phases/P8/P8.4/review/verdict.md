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
