# Read-only re-review (round 2, the last): item P8.3 of PLAN1 — the owning correction of round 1

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository (the kernel refuses
writes outside `vendor/`). KEEP IT FOCUSED on the correction below; no full suites, no phpstan/pint/rector. Other
sessions work in the same checkout: use ONLY the database `commerz_review2_test`. You may run, one at a time:
`DB_DATABASE=commerz_review2_test scripts/php84 vendor/bin/pest --do-not-cache-result packages/sales/tests/Feature/Carts packages/sales/tests/Feature/Checkout/PlacementTest.php tests/EndToEnd/CrmScenarioTest.php tests/EndToEnd/CrmMixedHostTest.php`
(PostgreSQL 127.0.0.1:54329, user/password `commerz`). Stop after ~15 minutes of work.

Repository: `/home/vostrikov/projects/packages/commerz`. The correction is commit `bc05856` (`git show bc05856`);
round 1 findings and verdict: `plans/2026.10.08-№1-COMMERZ-FOUNDATION/phases/P8/P8.3/review/{grok-answer.md,verdict.md}`;
decision `plans/…/decisions/D38.json`.

## The correction

1. sales `Carts::setCustomer` (`packages/sales/src/Carts/Internal/DatabaseCarts.php`) now stores the customer's groups
   from the host `CustomerDirectory` (read before the transaction) followed by the command's groups, deduplicated;
   without a customer, the command's groups. `SetCartCustomer` doc updated. Regression test in
   `packages/sales/tests/Feature/Carts/CartsTest.php` ('takes the customer groups from the host directory…').
2. `tests/EndToEnd/CrmScenarioTest.php`: the CRM passes no groups and gets `b2b` from its directory; the absent-tables
   check matches singular or `Str::plural` nouns.

## What to look for

- Does the correction fully close round-1 finding #2 without a new defect: every path that sets the cart customer or
  context (cart create with a context carrying a customer, `Orders::draft`, place-time profile read, CRM drafts,
  idempotent repeat of `setCustomer` / version bump, a directory that throws or is slow, a directory whose groups fail
  `CommerceContext` validation), consistency between quote and place, D38 vs docs/architecture (data.md, api.md).
- Does round-1 finding #1 hold for every noun of the absent packages (no false positives on installed tables)?
- Anything the correction broke elsewhere.

## Output

For every finding: severity, file:line, the defect in one sentence, and a REPRODUCTION. A finding without one is at
most "plausible". Finish with `Verdict: pass` or `Verdict: fail` and one paragraph of residual risk.
