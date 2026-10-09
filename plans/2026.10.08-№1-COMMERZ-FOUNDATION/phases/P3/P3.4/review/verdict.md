# Review of P3.1–P3.4 on grok/grok-4.7/high — verdict and disposition

- Request: owner message "ревью всей третьей фазы на grok 4.7" during `plan-run P3.4`.
- Reviewer session: grok 1.0.46, session `c7e96a76-a5bd-48ab-af46-c3d110964741`. The session record shows
  `current_model_id: grok-4.7`, `reasoning_effort: high` and `contextWindowTokens: 500000`. 37 turns, $1.77.
- Launch: the orch bridge was unavailable, and `--sandbox read-only` was refused (AppArmor userns), so grok ran with
  `--sandbox off` in a full copy of the repository in the executor's scratchpad. The main working tree fingerprint
  (status + sha256 of dirty files) was identical before and after the review.
- Reviewer verdict: `fail`, 5 findings (`grok-findings.json`, full answer `grok-answer.md`, stream
  `grok-stream.ndjson.gz`).

## Disposition (executor runs)

| ID | Item | Reviewer | Executor decision | Evidence |
|---|---|---|---|---|
| F1 | P3.4 | high | Rejected: not a defect | Restock reads the current links without a lock, as the design rules for link/unlink vs reserve (inventory-design#concurrency: links are read without a lock and a new link applies to later operations). The interleaving is serializable as restock-before-relink: the link change reads nothing restock writes. Units, receipt and movements stay consistent (INV-03). The reviewer's probe shows the race window exists, not that a result is wrong. |
| F2 | P3.4 | medium | Rejected: not a defect | Same argument for the channel source: restock serializes before the concurrent unassign. The chosen location is locked `for share` and rechecked active, so no stock lands on an archived location. A source list is routing configuration, not an invariant of stock. |
| F3 | P3.3 | medium | Rejected: not a defect; docs note for P3.7 | The backorder hold that created `reserved > on_hand` already published `StockShortfall` (the ledger compares shortfall before/after per command). A policy change does not touch levels. INV-02 constrains the reserve operation of tracked items. P3.7 should word invariants.md so that an excess left over from backorder is explicitly allowed after a switch to tracked. |
| F4 | P3.2 | medium | Confirmed and fixed | A new dataset `int64 overflow of backorder stock units` failed before the change. `AllocationPlanValidator` now checks `units × quantity_per_unit` for every component before skipping backorder free-stock limits. Unit suite: 61 passed. |
| F5 | P3.4 | low | Confirmed and fixed | `LedgerConcurrencyTest` gained a 2-process archive-vs-receive race (location and item, 10 rounds each). It holds today. Removing the entity `for share` lock in `DatabaseStockLevels` makes it fail 3/3 runs (mutant killed). |

No re-review round was run. The two fixes are local, and the executor's checks cover them (V1–V4 green, inventory 130 passed).
