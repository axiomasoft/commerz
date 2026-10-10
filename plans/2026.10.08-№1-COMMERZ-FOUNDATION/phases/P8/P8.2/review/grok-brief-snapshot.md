# Read-only review: item P8.2 of PLAN1 (Shop end-to-end scenario on all real packages)

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository: the kernel refuses
writes outside `vendor/`. KEEP IT FOCUSED: no full suites, no phpstan/pint/rector runs. Another session works in the
same checkout on other files: use ONLY the database `commerz_review_test` (never `commerz_test`). Run only, one at a
time, when you need a reproduction:
`DB_DATABASE=commerz_review_test scripts/php84 vendor/bin/pest --do-not-cache-result tests/EndToEnd/ShopScenarioTest.php`
(PostgreSQL 127.0.0.1:54329, user/password `commerz`). A reproduction you cannot write to disk may be given as a Pest
snippet plus the reasoning. Stop after ~20 minutes of work.

Repository: `/home/vostrikov/projects/packages/commerz` — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo.

## Scope (UNCOMMITTED, untracked files)

- `tests/EndToEnd/ShopScenarioTest.php` (new): steps 1–10 of the shop scenario on the seven real providers.
- `tests/EndToEnd/Support/CommittedDatabase.php` (new): migrate + truncate every `cmz_` table around committed tests
  (payments, refunds, carrier booking and the outbox relay refuse to run inside a transaction).

Ignore every other uncommitted change (`plans/`, other `tests/EndToEnd/*` files that may appear): not in this review.

## Contract

`plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/assembly-design.json` section `shop-scenario` (steps 1–10);
item record `phases/P8/P8.2.json` (scope, rules, validation); `tests/EndToEnd/Support/ShopWorld.php` (the world);
docs `docs/architecture/{extension,invariants,api}.md`; decisions D13, D22, D23, D31, D33 (`decisions/*.json`).
Logged deviations of P8.2 in `plans/…/log.jsonl` (`"item":"P8.2"`) are deliberate: (a) the scenario opts into
`commerz-promotions.restore_on` += `refunded` because the documented default restores only on cancel/expiry;
(b) the order's `quoteFingerprint` differs from the cart quote's by design (it carries the quote time).

## What to look for (most important first)

1. Does the test prove what the step list claims on the REAL packages (no fakes, no `Internal` imports): amounts
   (hand-derived in comments: −10% then −100 on 2500, D23 shares, 20% included VAT per line on the discounted base,
   courier 300 taxed), reservation/consumption/restock numbers, coupon and promotion counters, partial vs full refund.
   Is any expected number wrong but passing by coincidence, or any assertion vacuous?
2. Real defects in the packages the scenario should have exposed but did not assert (e.g. a step whose side effect is
   not checked: restock location, stock after refund, usage after the shortage path, cart state after QuoteChanged).
3. The outbox step: is the expected causal sequence right; can the listener + `commerz:outbox:relay --once` miss or
   duplicate events (batch size, delivered rows, other listeners); is "no duplicates" a meaningful check.
4. The `config([... restore_on ...])` inside the test: is it guaranteed to take effect (is `PromotionsConfig` possibly
   resolved before it), and does it leak to later tests in the same process.
5. `CommittedDatabase`: safety (only `cmz_` tables of the current schema on a `_test` DB), interaction with
   `RefreshDatabase` tests in the same process (AssemblyTest, ReverseBootTest) and with `PackageTestCase` migration
   state, identity restart, behaviour when the schema came from a different provider set.
6. Anything in the diff outside the item's scope.

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (failing
Pest snippet or command output). A finding without a reproduction is at most "plausible". Questions separately.
Finish with `Verdict: pass` or `Verdict: fail` and one paragraph of residual risk.
