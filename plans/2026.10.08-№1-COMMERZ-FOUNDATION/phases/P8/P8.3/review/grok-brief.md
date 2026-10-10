# Read-only review: item P8.3 of PLAN1 (CRM embedding: core + pricing + sales without catalog/inventory, and a mixed host)

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository: the kernel refuses
writes outside `vendor/`. KEEP IT FOCUSED: no full suites, no phpstan/pint/rector runs. Other sessions work in the
same checkout: use ONLY the database `commerz_review2_test` (never `commerz_test` or `commerz_review_test`). Run only,
one at a time, when you need a reproduction:
`DB_DATABASE=commerz_review2_test scripts/php84 vendor/bin/pest --do-not-cache-result tests/EndToEnd/CrmScenarioTest.php tests/EndToEnd/CrmMixedHostTest.php`
(PostgreSQL 127.0.0.1:54329, user/password `commerz`). A reproduction you cannot write to disk may be given as a Pest
snippet plus the reasoning. Stop after ~20 minutes of work.

Repository: `/home/vostrikov/projects/packages/commerz` — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo.

## Scope (UNCOMMITTED, untracked files)

- `tests/EndToEnd/CrmScenarioTest.php`: CRM subset (Commerz, Pricing, Sales; `commerz.stock=unlimited`; host plugin
  `tests.crm`, host `CustomerDirectory`) — draft → quote with tax → place → manual payment → fulfillment → completed;
  plus the absence of the shop packages' tables, the plugin/sellable set, the default ports and `commerz:doctor`.
- `tests/EndToEnd/CrmMixedHostTest.php`: the whole family + the CRM plugin; one cart with a catalog variant and a
  `crm.service`; only the variant is reserved.
- `tests/EndToEnd/Fixtures/{CrmPlugin,CrmServices,CrmCustomers}.php`: host implementations of public ports.

Ignore every other uncommitted change (`plans/`, `tests/EndToEnd/ShopScenarioTest.php`, `tests/EndToEnd/Support/*`
other than as context): not in this review.

## Contract

`plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/assembly-design.json` section `crm-scenario`; item record
`phases/P8/P8.3.json`; `docs/architecture/api.md` section "CRM embedding"; decisions D11, D14, D32.
Logged P8.3 events in `plans/…/log.jsonl` (`"item":"P8.3"`) are deliberate (separate mixed-host file; price fallback;
`default_zone`; groups via `SetCartCustomer`).

## What to look for (most important first)

1. Does the CRM test truly prove B3: sales/pricing work without catalog/inventory/shipping/promotions — or does some
   path silently depend on an absent package (a port resolved to a real class, a migration of another package that
   still ran, a config of another package that is read)? Is the absent-tables check meaningful (could it pass
   vacuously), and is the noun-prefix rule correct for every package (false negatives/positives)?
2. Real defects the CRM path should have exposed: customer groups vs `CustomerDirectory` profile (who is the source of
   truth for price groups at quote vs place; can a host's directory and the cart disagree silently?), tax without an
   address, `priceSource`, the draft lifecycle, fulfilment of services without stock, order completion.
3. Mixed host: SellableRouter routing by key in one cart, reservation of variant lines only, delivery and parcel of a
   cart with a service line, prices (fallback in a channel with prices including tax).
4. Test isolation: `migrate:fresh` in `beforeEach` of the CRM file and its effect on other test files in the same
   process (`PackageTestCase` migration tracking, `RefreshDatabase` state), `CommittedDatabase::truncate` afterEach.
5. Anything outside the item's scope.

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (failing
Pest snippet or command output). A finding without a reproduction is at most "plausible". Questions separately.
Finish with `Verdict: pass` or `Verdict: fail` and one paragraph of residual risk.
