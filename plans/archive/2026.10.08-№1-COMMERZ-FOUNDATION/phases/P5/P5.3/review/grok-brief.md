# Read-only review: item P5.3 of PLAN1 (promotions built-in conditions, effects, automatic activator)

You are an independent reviewer. You may read everything and run read-only commands (tests, phpstan). You must not
modify the repository. Inspect, report findings, stop.

Repository: `/home/vostrikov/projects/packages/commerz` — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo.
PHP commands go through `scripts/php84`. Promotions tests: `scripts/php84 vendor/bin/pest --testsuite=Promotions`
(PostgreSQL at 127.0.0.1:54329).

## Scope under review (UNCOMMITTED working tree; see `git status --short packages`)

- `packages/promotions/src/Rules/Internal/Conditions/{LineSellableIn,LineCategoryIn,CustomerInGroup,CartSubtotalAtLeast,CartQuantityAtLeast}.php`
- `packages/promotions/src/Rules/Internal/Effects/{LinePercentOff,LineFixedOff,OrderPercentOff,OrderFixedOff,DeliveryPercentOff,BuyXGetY,UnitPool,OrderNet}.php`
- `packages/promotions/src/Rules/Internal/Activators/AutomaticActivator.php`
- `packages/promotions/src/Internal/PromotionsPlugin.php` (contributions)
- tests: `packages/promotions/tests/{Support/RuleStates.php,Unit/Rules/Conditions,Unit/Rules/Effects,Feature/Rules/BuiltInRulesPluginTest.php}`

Ignore uncommitted changes under `plans/` (owner planning work) except as design input.

## Contract to review against

- `plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/promotions-design.json` sections `rules-spi`, `builtins`, `golden`
  (G9–G12 for BXGY), `engine`; decisions D26, D15 (sequential stacking: base is always the current QuoteState,
  never `QuoteLine::subtotal()`); item record `phases/P5/P5.3.json` (scope, rules, why/risks).
- SPI types: `packages/promotions/src/Rules/{Contracts,Data}`; kernel: `packages/core/src/Quotes/Data/QuoteState.php`,
  `packages/core/src/Money/{Money,MoneyAllocator}.php`.

## What to look for (most important first)

1. Correctness against the design: parameter canon (sorted/deduped lists, explicit defaults, `only()`), semantics of
   each rule, rounding (explicit `RoundingMode`), a discount never above its own base, order base =
   items net + order adjustments, UnitPool split vs `MoneyAllocator` (remainder to lowest index), BXGY with no unit
   materialization and deterministic tie-break (PRM-04), cheapest selection across lines.
2. Fail-closed / robustness: malformed stored params reaching `evaluate`/`apply`, currency mismatch, int overflow,
   unknown line ids, string line ids like '1' as PHP array keys.
3. Purity: no DB, network, `now()`; rules are `final readonly` and stateless.
4. SPI/SemVer surface: nothing public added outside Internal; attributes; arch rules.
5. Test gaps that hide a real defect.

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (a failing
Pest test snippet or a command with its output). A finding without a reproduction is at most "plausible". Finish with
a verdict line `Verdict: pass` or `Verdict: fail` and the list of findings. Do not propose broad refactors.
