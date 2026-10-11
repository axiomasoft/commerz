# Read-only review: item P8.1 of PLAN1 (Assembly: EndToEnd harness, ShopWorld, port matrix, release manifests)

You are an independent reviewer. Inspect, report findings, stop. Do NOT modify the repository: the kernel refuses
writes outside `vendor/`. KEEP IT FOCUSED: no full suites, no phpstan/pint/rector runs. Run only, one at a time and
with `--do-not-cache-result`, when you need a reproduction:
`scripts/php84 vendor/bin/pest --do-not-cache-result --testsuite=EndToEnd` and
`scripts/php84 vendor/bin/pest --do-not-cache-result packages/core/tests/Feature/PluginsTest.php`
(PostgreSQL 127.0.0.1:54329, isolated `commerz_test`). A reproduction you cannot write to disk may be given as a Pest
snippet plus the reasoning. Stop after ~20 minutes of work.

Repository: `/home/vostrikov/projects/packages/commerz` — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo.

## Scope (UNCOMMITTED working tree: `git status`, `git diff` and the untracked files below)

- New: `tests/EndToEnd/{AssemblyTest.php, Support/{EndToEndTestCase,ShopWorld,PortMatrix}.php}`.
- Changed: `tests/Pest.php`, `phpunit.xml`, root `composer.json` (+`composer.lock`: the 9 path packages dev-main → 0.1.x-dev),
  `packages/*/composer.json` (inter-package `^0.1@dev` → `^0.1`).
- Product fix found by the assembly: `packages/core/src/CommerzServiceProvider.php` (`PluginCatalog` bound with
  `singletonIf` instead of `singleton`: a feature provider that registered before the core lost its built-in plugin) and its
  regression test in `packages/core/tests/Feature/PluginsTest.php`.

Ignore other uncommitted changes (`plans/`): owner work.

## Contract

`plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/assembly-design.json` sections `baseline`, `assembly`, `shop-scenario`;
decisions D36, D16, D3, D22 (`decisions/*.json`); item record `phases/P8/P8.1.json` (scope, rules, validation V1–V7).
Logged deviations of P8.1 in `plans/…/log.jsonl` (`"item":"P8.1"`) are deliberate.

## What to look for (most important first)

1. Does the assembly really prove what it claims: every port bound to the real implementation for any provider order;
   no fakes; no import of `Internal` classes in the tests (class names as strings only); stage order proven from the
   public QuoteStage contract; the reverse-order test truly boots a fresh application in reverse order and is red without
   the core fix.
2. The core fix: other places where provider registration order still matters (singleton vs singletonIf, static state,
   `Container::getInstance()` vs `$this->app`, anything a feature provider's `register()` touches before the core's).
3. ShopWorld: only the public API; matches the world described in `assembly-design#shop-scenario` (stock S5/M2/mug10,
   prices, VAT 20%, auto −10%, coupon SALE100 −100 with 1 per customer / 5 total, courier 300 free above 5000); the
   `taxIncluded: true` of the courier rate (expected "in-price" delivery) is a deliberate choice; challenge only if wrong.
   It must stay stable for P8.2/P8.4 (readonly, ids/refs only).
4. Manifests (D36): `^0.1` constraints, no `version`, branch-alias kept, root `options.versions` for 9 packages; does
   `composer validate --strict --no-check-lock -d packages/<pkg>` pass for each; a published manifest must install
   without `@dev` (check the root + lock reasoning).
5. Test isolation: DB writes only through RefreshDatabase on `commerz_test`; the static reverse flag cannot leak to other tests.
6. Anything in the diff outside the item's scope.

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (failing
Pest snippet or command output). A finding without a reproduction is at most "plausible". Questions separately.
Finish with `Verdict: pass` or `Verdict: fail` and one paragraph of residual risk.
