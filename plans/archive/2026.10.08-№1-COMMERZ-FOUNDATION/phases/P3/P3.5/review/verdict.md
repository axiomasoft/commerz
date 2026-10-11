# Review of P3.5 on grok/grok-4.7/high — verdict and disposition

- Request: owner message "ревью на grok 4.7" during `plan-run P3.5` (selector grok/grok-4.7, effort high by default).
- Reviewer session: grok 1.0.46, session `01a11fb4-44e5-76b1-a82e-28508641b4d1`. The session record shows
  `modelId: grok-4.7`, `reasoning_effort: high`, `contextWindowTokens: 500000`. 45 turns, 44.5 min, $1.35.
- Launch: the orch bridge was unavailable (`BRIDGE_PARTNER_UNAVAILABLE`); as in P3.4, grok ran headless with
  `--sandbox off` in a full copy of the repository in the executor's scratchpad. The main working tree fingerprint
  (sha256 of dirty files) differed afterwards only in the files of the executor's own correction below.
- Reviewer verdict: `fail`, 3 findings (`grok-findings.json`, answer `grok-answer.md`, stream `grok-stream.ndjson.gz`).

## Disposition (executor runs)

| ID | Reviewer | Executor decision | Evidence |
|---|---|---|---|
| F1 | high | Confirmed and fixed | New test `consumes untracked and unlimited sellables the demand reserved without holding stock` failed before (ReservationNotFound). `consume` of a sellable with no lines that resolves to untracked/unlimited now ships nothing, closes a settled header and raises `StockConsumed` with no allocations; an unknown demand still fails. |
| F2 | medium | Confirmed and fixed | New test `repeats a live demand that holds only untracked sellables without writing` failed before (generation 2). Repeat rule is now: live header (active, not expired) or anything consumed → return what it holds; closed or expired with nothing consumed → next generation. |
| F3 | medium | Confirmed and fixed | New test `gives a backorder sellable nothing on a channel without sources` failed before (null). Availability answers 0 for a stocked sellable when the channel has no active source, whatever the strategy's capacity says. |

One owning correction; checks rerun: V1–V3 green, inventory 169 passed, arch 13 passed. No re-review round.
