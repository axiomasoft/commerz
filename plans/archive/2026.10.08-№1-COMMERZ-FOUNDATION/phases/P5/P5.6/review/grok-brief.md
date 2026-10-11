# Read-only review: item P5.6 of PLAN1 (promotion engine: quote session, activation, stage algorithm, three stages, explain)

You are an independent reviewer. You may read everything and run read-only commands (tests, phpstan). You must not
modify the repository. Inspect, report findings, stop.

Repository: `/home/vostrikov/projects/packages/commerz` — Laravel 13 / PHP 8.4 / PostgreSQL ≥16 package monorepo.
PHP commands go through `scripts/php84`. Promotions tests: `scripts/php84 vendor/bin/pest --testsuite=Promotions`
(PostgreSQL at 127.0.0.1:54329). Evaluation tests only: `scripts/php84 vendor/bin/pest packages/promotions/tests/Feature/Evaluation`.

## Scope under review (UNCOMMITTED working tree; see `git status --short packages`)

- new `packages/promotions/src/Evaluation/Internal/{ActivePromotions,CandidatePromotion,DiscountGuard,PromotionEngine,PromotionExplainer,PromotionSession,PromotionSessions}.php`
- new `packages/promotions/src/Evaluation/Internal/Stages/{Catalog,Cart,Delivery}PromotionsStage.php`
- new public data `packages/promotions/src/Definitions/Data/{ExplainPromotions,PromotionExplanation,PromotionVerdict}.php`,
  `packages/promotions/src/Definitions/Enums/VerdictKind.php`
- modified `packages/promotions/src/{Internal/PromotionsPlugin.php,PromotionsServiceProvider.php,Definitions/Contracts/PromotionDefinitions.php,Definitions/Internal/DatabasePromotionDefinitions.php,Rules/Data/ActivationRequest.php}`
- tests: `packages/promotions/tests/Feature/Evaluation/*`, `packages/promotions/tests/Fixtures/{EvaluationPlugin,FlatDeliveryStage,RogueLineFilter,RogueEffect,RogueActivator}.php`,
  `packages/promotions/tests/Feature/PromotionsPluginTest.php`

Ignore uncommitted changes under `plans/` (owner planning work) except as design input.

## Contract to review against

- `plans/2026.10.08-№1-COMMERZ-FOUNDATION/knowledge/promotions-design.json` sections `engine` (core of this item),
  `golden` (G1–G22), `rules-spi`, `builtins`, `invariants` (PRM-04, PRM-05); decisions D26, D15, D25, D5
  (`decisions/*.json`); item record `phases/P5/P5.6.json` (scope, rules, guidance, why/risks).
- Recorded deviations of this item (in `log.jsonl`, item P5.6): every contributed activator is asked when codes are
  presented; code rejections in presentation order; a code-activated Activation must carry a couponCode; negative
  Discount is unrepresentable (cause `promotions.invalid_discount`); `PromotionSessions::forget` used by explain.
- Kernel: `packages/core/src/Quotes/{Data/QuoteState.php,Data/QuoteInput.php,Data/Adjustment.php,Data/Rejection.php,Internal/StagedQuotePipeline.php,Exceptions/QuoteFailed.php}`.
- Already-reviewed earlier items (P5.2–P5.5: rules, built-ins, coupon activator) are context, not scope.

## What to look for (most important first)

1. Correctness of the stage algorithm against `#engine`: candidate order (priority DESC, code ASC), exclusive /
   stopFurther / not_combinable, limit hints (usage, per-customer, customer_required), two-pass conditions
   (line filters then qualifiers), effects on the scratch state `next`, clamping (line / order net incl. order
   adjustments / delivery), budget check, whole-or-nothing application (PRM-05), Rejection only for code-activated
   promotions, verdicts for explain, code-level rejections (too_many_codes, unknown_code, activator rejections, first
   activator by key ASC owns a code).
2. Trust of plugins: any way a condition, effect or activator can make the engine produce a wrong quote instead of
   failing it (foreign line, foreign currency, wrong target for the stage, a promotion it was not asked about, twice,
   a code not presented, int-like line ids such as '1' used as array keys).
3. Session: one load per run across three stages, a fresh load when the same QuoteInput runs again, the WeakMap never
   keeping an input alive (Octane/workers), explain not showing a stale session, no writes and no `now()` (time from
   `context.at`).
4. Query correctness: window/currency/channel filters, ordering determinism, customer usage lookup.
5. Public surface (SemVer): new Data/Enum types and the `explain` contract method; attributes; arch rules.
6. Test gaps that hide a real defect.

## Output

For every finding: severity (high/medium/low), file:line, the defect in one sentence, and a REPRODUCTION (a failing
Pest test snippet or a command with its output). A finding without a reproduction is at most "plausible". Finish with
a verdict line `Verdict: pass` or `Verdict: fail` and the list of findings. Do not propose broad refactors.
