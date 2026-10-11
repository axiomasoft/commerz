# Review of P7.4–P7.5 on grok/grok-4.7/high — verdict and disposition

- Request: owner message "в конце ревью на grok 4.7 сразу по пунктам 7.4-7.5" during `plan-run P7.5` (no context or effort
  given; effort defaults to high).
- Launch: orch bridge `launch reviewer grok --model grok-4.7 --effort high` (ro-run.py Landlock read-only wrapper), brief in
  `grok-brief.md`, against the committed range `0efccac..4565bc3` after V1–V5 were green.
- Reviewer session `01a12436-4270-7560-8eef-aa31559c0af0`: `modelId grok-4.7` (served as `grok-4.7-build`),
  `reasoning_effort: high` in `chat_history.jsonl`, `contextWindowTokens: 500000` in `signals.json`; 33 turns, ~24 min.
  Answer `grok-answer.md`, stream `grok-stream.ndjson.gz`. The reviewer reproduced its findings in a throwaway Pest file.
- Reviewer verdict: `fail`, 2 high findings, 1 question.

## Disposition (executor reproduced both by its own run: 3 red tests before the fix)

| ID | Severity | Executor decision | Fix and regression test |
|---|---|---|---|
| 1 | high | Confirmed: a `failed`/`authorized` webhook that overtakes the synchronous answer made `finish` drop a `captured` result (status fence), freeing the balance for a second full payment. | `PaymentJournal::finish` fences by `attempts` only (a reconcile bumps it before calling the gateway); the answer is applied to the payment as it is now through `PaymentTransitions`, which never regress; `initiating → unknown` stays limited to `initiating`. Deviation from the `status = 'initiating'` guidance logged. Tests: `InitiatePaymentTest` "keeps the capture the gateway answered after a webhook moved the attempt on" (failed, authorized; `RecordingGateway::during`), `PaymentJournalFenceTest` "applies the answer of its attempt to a payment an event moved meanwhile". |
| 2 | high | Confirmed: a zero-total order was placed `unpaid` and expired, releasing its reservation and restoring promotion usage. | `OrderWriter` (P7.3/P7.4 code) writes the payment state `OrderStatus::derive` gives at placement: a free order is born `paid`, so expiry never selects it. Test: `ExpiryTest` "never expires an order that owes nothing". |

Q1: a partial capture frees the rest of the balance by design (`committed` counts a captured attempt by what it took,
sales-design#payments); a later larger capture on the same attempt is an overpay, signalled once by
`sales.payment_requires_refund` (OrderStatus::refresh), never silent. Kept.

Residual risk accepted by the design: a reconcile after the lease lapses can fail a slow initiate on `find === null` and
fence its late answer; `lease_seconds` must exceed the gateway HTTP timeout (sales-design#payments).

Checks after the fix: V1 lint, V2 PHPStan, V3 Rector, V4 Sales (340 passed), V5 Arch — all exit 0.
