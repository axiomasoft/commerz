# Review of P5.3 on grok/grok-4.7/high — verdict and disposition

- Request: owner message "ревью на grok 4.7" during `plan-run P5.3` (no context size or effort given; effort defaults to high).
- Launch: orch bridge `launch reviewer grok --model grok-4.7 --effort high` (ro-run.py read-only wrapper), brief in
  `grok-brief.md`, run against the uncommitted working tree after V1–V5 were green.
- Reviewer session: `01a12124-fef9-7fb3-bbbc-340a246ff27f`. `~/.grok/sessions/.../summary.json`: `current_model_id: grok-4.7`,
  `reasoning_effort: high`; 41 model calls, 25 min. No `contextWindowTokens` in this record; `~/.grok/config.toml` sets 500000
  and the selector requested no capacity.
- Reviewer verdict: `fail`, 4 findings (answer in `grok-answer.md`, stream `grok-stream.ndjson.gz`). The reviewer also
  ran PHPStan max on the scope (clean), compared `UnitPool` with `MoneyAllocator` on 10 025 pairs and 200 random BXGY carts
  against an independent oracle (all matched).

## Disposition (executor reproduced each by its own run: 5 red tests before the fix)

| ID | Severity | Executor decision | Fix and regression test |
|---|---|---|---|
| 1 | medium | Confirmed | `LineFixedOff::perUnit` clamps without the product (`amount ≥ ceil(net / quantity)` → net). `EffectsTest`: "clamps a per-unit fixed discount whose amount times quantity exceeds int64" |
| 2 | medium | Confirmed (stored data bypasses `normalize` when the version is current) | `CartSubtotalAtLeast::evaluate` throws `InvalidRuleConfig('amount')` for a non-positive minimum; same guard added to `LineFixedOff::apply` and `OrderFixedOff::apply` (same class of defect). Tests in `ConditionsTest` and `EffectsTest` |
| 3 | low | Confirmed | `UnitPool::of` refuses a unit count that does not fit an int (`promotions.invalid_unit_pool`). `BuyXGetYTest` |
| 4 | low | Confirmed | `BuyXGetY` clamps a negative line net to 0: its units stay in the pool, free, and yield no discount, as `line.percent_off` does. `BuyXGetYTest` |

After the correction: V1–V5 exit 0, Promotions 175 passed. No second review round was run; the correction is local to the
four rule files and covered by the reproductions above.
