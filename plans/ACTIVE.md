# Active plans

- PLAN1 — `plans/2026.10.08-№1-COMMERZ-FOUNDATION/`: Commerz architecture, public API and v0 build.
  Read state with `python3 <Task package root>/scripts/plan.py show PLAN1` / `next PLAN1`.
  P1 and P2 (catalog) done, audited and corrected — report in `knowledge/audit-p1-p2-2026-10-09.json`; updated contracts in `knowledge/kernel-contracts` and `knowledge/catalog-design`. D20 records nested savepoint atomicity and the outbox relay transaction boundary. P3 (inventory) done (7/7); audit and corrections in `knowledge/audit-p3-2026-10-09.json`, updated design in `knowledge/inventory-design`, D21 (model), D22 (additive Ports\Stock change). P4 (pricing & tax) detailed: 7 items, design in `knowledge/pricing-design`, D23 (additive kernel change in P4.1: shared order-adjustment allocation, Money::includedPart, exclusion constraints), D24 (price selection and tax model); next `task:plan-run 2026.10.08-№1-COMMERZ-FOUNDATION P4.1`. P5–P7 still skeleton (D16: detail against the real kernel, then run in parallel); P8 last. All decisions D1–D24 accepted; owner delegated architecture authority (see CLAUDE.md).

Research corpus: `docs/discovery/2026-10-08/README.md`, `audits/`.
