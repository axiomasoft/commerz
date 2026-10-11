# Review of P7.1–P7.3 on grok/grok-4.7/high — verdict and disposition

- Request: owner message "в конце ревью на grok 4.7 сразу по трем пунктам 7.1-7.3" during `plan-run P7.3` (no context or
  effort given; effort defaults to high).
- Launch: orch bridge `launch reviewer grok --model grok-4.7 --effort high` (ro-run.py Landlock read-only wrapper), brief in
  `grok-brief.md`, against the committed range `5e78878..3026393` after V1–V5 were green.
- Reviewer session `01a123e7-dabd-7f50-bb96-a5ba4a163bec`: stream model `grok-4.7`, `reasoning_effort: high` in
  `chat_history.jsonl`, `contextWindowTokens: 500000` in `signals.json`; 89 turns, 34 min. Answer `grok-answer.md`,
  stream `grok-stream.ndjson.gz`. The reviewer ran C1–C3 and the snapshot, after-commit and duplicate-number tests (green).
- Reviewer verdict: `fail`, 2 medium findings, 1 question.

## Disposition (executor reproduced both by its own run: 2 red tests before the fix)

| ID | Severity | Executor decision | Fix and regression test |
|---|---|---|---|
| 1 | medium | Confirmed (SQLSTATE 22001 on a 44-byte locale; same class for sellable name, customer name/email) | Shared model, not a local patch: every sales column fed by a core string without a bound is `text` — `carts.locale`, `orders.{locale, customer_email, customer_name}`, `order_lines.{name, tax_class, price_source}`, `fulfillments.{carrier, tracking, label_ref}` (booking port, for P7.6). The snapshot keeps them in full (ORD-02), no truncation. `GatewayPaymentResult`/`GatewayRefundResult::externalId`, which sits in `UNIQUE(gateway, external_id)` varchar(191), is bounded at the SPI instead (`InvalidCommand`). Tests: `PlacementTest` "keeps texts the core does not bound in full", `GatewayDataTest` 192-byte external ids. |
| 2 | medium | Confirmed (`InvalidOrderNumber` for prefix `-`) | The core allows a leading hyphen in `StoreView::orderNumberPrefix`; the sales number grammar becomes 1..64 of `A-Za-z0-9-` with at least one letter or digit (placement check, `orders.number` CHECK, SPI docblock). Tests: `PlacementTest` "prefix starts with a hyphen", `SchemaTest` number cases (`-1001` valid, `-`/`---` refused). |

Q1: `knowledge/invariants.json#placement` still says lock timeouts are retried three times. D20 (global, later: no automatic
retry; the caller repeats the whole operation) and `sales-design#placement` are binding and `OrderPlacement` follows them.
`invariants.json` is an uncommitted owner file and was left untouched; its sentence should be aligned with D20.

Checks after the fix: V1 lint, V2 PHPStan, V3 Rector, V4 Sales (173 passed), V5 Arch — all exit 0.
