# Active plans

- PLAN1 — `plans/2026.10.08-№1-COMMERZ-FOUNDATION/`: Commerz architecture, public API and v0 build.
  Read state with `python3 <Task package root>/scripts/plan.py show PLAN1` / `next PLAN1`.
  P1 and P2 (catalog) done, audited and corrected — report in `knowledge/audit-p1-p2-2026-10-09.json`; updated contracts in `knowledge/kernel-contracts` and `knowledge/catalog-design`. Final checks pass (483 tests). D20 records nested savepoint atomicity and the outbox relay transaction boundary. P3 (inventory) detailed: 7 items, design in `knowledge/inventory-design`, D21 (model), D22 (additive Ports\Stock change in P3.1); next is P3 execution (`task:plan-run … P3.1 P3.2`). P4–P7 still skeleton (D16: detail against the real kernel, then run in parallel); P8 last. All decisions D1–D22 accepted; owner delegated architecture authority (see CLAUDE.md).

Research corpus: `docs/discovery/2026-10-08/README.md`, `audits/`.
