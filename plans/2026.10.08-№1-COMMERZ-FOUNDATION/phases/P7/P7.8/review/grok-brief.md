# Read-only review: item P7.8 of PLAN1 (sales: Refunds)

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository: the kernel refuses
writes outside `vendor/`. KEEP IT FOCUSED: no full suites, no phpstan/pint/rector runs. Run at most these test files,
one at a time, only when you need a reproduction, always with `--do-not-cache-result`:
`scripts/php84 vendor/bin/pest --do-not-cache-result packages/sales/tests/Feature/Refunds/<File>.php`
(PostgreSQL 127.0.0.1:54329; the tests commit data and truncate the sales tables afterwards). A reproduction you
cannot write to disk may be given as a Pest snippet plus the reasoning. Stop after ~20 minutes of work.

Repository: `/home/vostrikov/projects/packages/commerz` — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo.

## Scope (UNCOMMITTED working tree: `git status`, `git diff` and the untracked files below)

- New: `packages/sales/src/Refunds/**` (Contracts/Refunds, Data/{RefundId, IssueRefund, ReconcileRefund,
  RecordRefundEvent, RefundEventResult, RefundView, ListRefunds}, Enums/RefundStatus, Events/RefundStatusChanged,
  Exceptions/*, Internal/{RefundWorkflow, RefundJournal, DatabaseRefunds, RefundRow, RefundablePayment}).
- Changed: `packages/sales/src/{Sales.php, Facades/Sales.php, SalesServiceProvider.php}`,
  `packages/sales/tests/Fixtures/RecordingGateway.php`, `packages/sales/tests/Feature/Helpers.php`,
  `packages/sales/tests/Unit/Payments/PaymentGatesTest.php`.
- Tests: `packages/sales/tests/Feature/Refunds/*`, `packages/sales/tests/Unit/Refunds/*`.

Ignore other uncommitted changes (`plans/`, `packages/sales/tests/Feature/SalesRootTest.php`): owner work.

## Contract

`plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/sales-design.json` sections `returns-refunds`, `payments`, `locks`,
`model`, `api`, `spi`, `tests` (C7); decisions D33, D13, D27 (`decisions/*.json`); item record `phases/P7/P7.8.json`.
Reference implementation of the same protocol: `packages/sales/src/Payments/Internal/{PaymentWorkflow,PaymentJournal}.php`.
Logged deviations of P7.8 in `plans/…/log.jsonl` (`"item":"P7.8"`) are deliberate — challenge them only if they
break an invariant.

## What to look for (most important first)

1. Money: never refund more than a payment captured (refundable = captured − Σ refunds not failed, under order +
   payment locks with the insert); a timeout/invalid answer is `unknown` and keeps holding its amount; a declined or
   not-found refund is `failed` and frees it; no refund applied twice (inbox, fencing by attempts, idempotency key).
2. Lock order and deadlocks: order → [PromotionUsage::restore(Refunded) when the refund completes the order] →
   payment → refund; races between issue/finish/recordEvent/reconcile and payment events, cancel, expiry.
3. Gateway called only outside a DB transaction (OperationInsideTransaction before any write); lease and fencing in
   reconcile; status monotonic (succeeded/failed final).
4. Promotion restore: exactly when the order becomes fully refunded, idempotent, rolled back with the transaction.
5. REF-01: nothing in Refunds touches stock. Derived order state / payments.refunded_minor / outbox
   `sales.refund_succeeded` / events / journal consistent; CHECK constraints cannot be violated.
6. Public surface/SemVer: #[Api] on public types, no Eloquent/float, no import of another module's Internal, error
   codes vs `sales-design#api`.
7. Test gaps that hide a real defect.

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (failing
Pest snippet or command output). A finding without a reproduction is at most "plausible". Questions separately.
Finish with `Verdict: pass` or `Verdict: fail` and one paragraph of residual risk.
