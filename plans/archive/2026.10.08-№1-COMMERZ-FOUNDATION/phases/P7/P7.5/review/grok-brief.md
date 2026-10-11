# Read-only review: items P7.4 and P7.5 of PLAN1 (sales: order lifecycle, payments)

You are an independent reviewer. You may read everything and run read-only commands (tests, phpstan, git). You must not
modify the repository. Inspect, report findings, stop.

Repository: `/home/vostrikov/projects/packages/commerz` — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo.
PHP commands go through `scripts/php84`. Sales tests: `scripts/php84 vendor/bin/pest --testsuite=Sales` or a single file
(PostgreSQL at 127.0.0.1:54329; payment and concurrency tests commit data and truncate the sales tables afterwards, and
the concurrency tests fork with pcntl — run test files one at a time).

## Scope under review (COMMITTED: `git diff 0efccac..4565bc3 -- packages/sales`)

- P7.4 `24c7816`: `Common/Internal/{OrderLocks, OrderLedger, OrderStatus, OrderStatusChange, OrderRow, OrderLineRow}`,
  `Common/{Data,Exceptions}`, `Orders/Internal/{OrderCancellation, OrderExpiry}`, `Orders::cancel|cancelLines|expireDue`,
  console `commerz:sales:expire-orders`, tests under `packages/sales/tests/{Feature/Orders, Unit/Orders}`.
- P7.5 `4565bc3`: `Payments/*` (`Internal/{PaymentWorkflow, PaymentJournal, PaymentTransitions, PaymentRow, DatabasePayments}`),
  `Common/Internal/PaymentEventInbox`, `Sales::payments()`, fixture `tests/Fixtures/RecordingGateway`, tests under
  `packages/sales/tests/{Feature/Payments, Unit/Payments}` and `Feature/Orders/ExpiryTest.php`.

Ignore uncommitted changes under `plans/` (owner planning work) except as design input.

## Contract to review against

- `plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/sales-design.json` sections `model`, `spi`, `lifecycle`, `payments`,
  `locks`, `api`, `tests`; decisions `decisions/D29.json`, `D30.json`, `D32.json`, `D33.json` (journal, lease, fencing,
  unknown → reconcile, inbox), D20 (nested `Transactions::run` = savepoint, no automatic retry).
- Item records `phases/P7/P7.4.json`, `P7.5.json` (scope, rules, why/risks); logged deviations of both items in
  `log.jsonl` (`"item":"P7.4"` / `"item":"P7.5"`) are deliberate decisions — challenge them only if they break an invariant.
- Reference protocol already reviewed: `packages/shipping/src/Bookings/Internal/{CarrierShipmentBooking, BookingJournal}.php`.

## What to look for (most important first)

1. Money (PAY-01, PAY-02): a doubled or reordered webhook, a repeat after a timeout, or two parallel initiates must
   never double `captured`, exceed the order balance, or regress a status; a gateway exception other than
   `PaymentDeclined` must never yield `failed`; the gateway is never called inside a DB transaction; Tx B writes only
   under the attempt it was asked under (fencing), including races of initiate-finish vs reconcile and reconcile vs
   reconcile; `PaymentTransitions` monotonic; the inbox claim precedes any transition and its fingerprint is stable.
2. Order states and amounts (`OrderStatus::refresh/derive`): `amount_due`, captured/refunded sums, the three states,
   `payment_requires_refund` published exactly once per growth of money held over the amount due (cancel of a paid
   order, cancelLines, capture after cancellation), completed/cancelled final, journal/outbox/events consistent.
3. ORD-03 ledger: `OrderLedger` sole writer with conditional updates; cancel/cancelLines/expiry under `OrderLocks`,
   stock release and promotion restore (Expired vs Cancelled), partial cancel value allocation, idempotent cancel.
4. Expiry (D32): `FOR UPDATE SKIP LOCKED`, re-check under the lock, a payment under way blocks expiry; races with a
   capture or a cancel.
5. Lock order vs `sales-design#locks` (order → payment → inbox) and deadlock risk across the new paths.
6. Public surface / SemVer: types outside `Internal` carry `#[Api]`/`#[Spi]`, no Eloquent/float in signatures, a module
   does not import another module's `Internal` (only `Common/Internal`); error codes and categories vs `sales-design#api`.
7. Test gaps that hide a real defect (including C4, C5, C8 and the fence and gate tests).

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (a failing
Pest test snippet or a command with its output). A finding without a reproduction is at most "plausible". List
questions separately. Finish with a verdict line `Verdict: pass` or `Verdict: fail` and a one-paragraph residual risk.
