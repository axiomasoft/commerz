# Read-only review: item P5.7 of PLAN1 (PromotionUsage: consume/restore under the D27 lock protocol, reconcile, concurrency tests — PRM-01)

You are an independent reviewer. You may read everything and run read-only commands (tests, phpstan, psql reads). You
must not modify the repository. Inspect, report findings, stop.

Repository: `/home/vostrikov/projects/packages/commerz` — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo.
PHP commands go through `scripts/php84`. Promotions tests: `scripts/php84 vendor/bin/pest --testsuite=Promotions`
(PostgreSQL at 127.0.0.1:54329). Usage tests only: `scripts/php84 vendor/bin/pest packages/promotions/tests/Feature/Usage packages/promotions/tests/Feature/Console`.
The concurrency tests fork processes (`Commerz\Testkit\Concurrency::run`) and commit data, then truncate.

## Scope under review (UNCOMMITTED working tree; see `git status --short packages`)

- new `packages/promotions/src/Usage/Exceptions/PromotionUnavailable.php` (public `#[Api]`)
- new `packages/promotions/src/Usage/Internal/{DatabasePromotionUsage,UsageReconciler,Discrepancy}.php`
- new `packages/promotions/src/Internal/Console/ReconcilePromotionsCommand.php` (`commerz:promotions:reconcile`)
- modified `packages/promotions/src/PromotionsServiceProvider.php` (port binding, command)
- tests: `packages/promotions/tests/Feature/Usage/{Helpers,PromotionUsageTest,UsageConcurrencyTest}.php`,
  `packages/promotions/tests/Feature/Console/ReconcilePromotionsCommandTest.php`

Ignore uncommitted changes under `plans/` (owner planning work) except as design input.

## Contract to review against

- `plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/promotions-design.json` sections `usage` (core of this item: lock
  modes table, consume steps 0–5, restore steps 1–5, interfering operations, C1–C7), `schema`, `api` (error codes,
  reconcile), `invariants` (PRM-01); `knowledge/invariants.json#placement` (global lock order); decisions D27, D13,
  D25, D20 (`decisions/*.json`); item record `phases/P5/P5.7.json` (scope, rules, guidance, why/risks).
- Recorded deviation of this item (in `log.jsonl`, item P5.7): reconcile logic in `Usage/Internal/UsageReconciler`
  and it also reports unsupported activators; a non-canonical coupon code is `coupon_not_found`; promotion ids are
  lowercased before the fingerprint; a restored redemption stays a no-op for a repeated consume of the same payload.
- Kernel: `packages/core/src/Ports/Promotions/**`, `packages/core/src/Operations/{Fingerprint.php,Contracts/Transactions.php}`
  and the Transactions implementation (savepoint semantics, D20).
- Already-reviewed earlier items (P5.4 definitions + `UsageCounters::recompute`, P5.5 coupons, P5.6 engine) are
  context, not scope — but report a defect there if it breaks PRM-01 together with this item.

## What to look for (most important first)

1. PRM-01 under concurrency: any interleaving (READ COMMITTED) that lets a total, coupon, per-customer limit or budget
   be exceeded, loses a usage when a limit is set/changed or a promotion paused (KEY SHARE vs FOR UPDATE), counts an
   order twice, or deadlocks (40P01): lock order header → coupons → promotions → customer usages, each id ASC;
   KEY SHARE on every promotion/coupon row before conditional updates; counters touched only when the limit is set.
2. Idempotency: UNIQUE(order_ref) header + fingerprint; concurrent same-order consumes; IdempotencyConflict on a
   different payload; restore idempotent, policy `restore_on`, unknown order no-op; interplay of restore and recompute
   (`greatest(…, 0)`).
3. Transaction behaviour: joins the caller's transaction (savepoint), any exception rolls back everything of the
   placement, no statement that can raise inside the host transaction without a savepoint.
4. Reconcile: read-only snapshot, correct expected values (counters zero without a limit; per-customer rows), output
   and exit codes; unsupported rules/activators.
5. Public surface (SemVer): `PromotionUnavailable` (code `promotions.promotion_unavailable`, category conflict, fields
   promotionId/couponCode/reason), port binding replacing the kernel's `NullPromotionUsage`; arch rules.
6. Test gaps that hide a real defect (e.g. a C-test that would pass with a broken implementation).

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (a failing
Pest test snippet or a command with its output). A finding without a reproduction is at most "plausible". Finish with
a verdict line `Verdict: pass` or `Verdict: fail` and the list of findings. Do not propose broad refactors.
