The promotion engine matches the P5.6 contract. No defects found.

Checked against `promotions-design` `#engine` (and the recorded P5.6 deviations), D26, D15, D25, and D5:

- The stage loop orders candidates by priority descending, then code ascending. Exclusive and `stopFurther` stop only after a successful apply. Limits, the two condition passes, scratch-state effects, clamping (including the order net), the budget check on the clamped total, and whole-or-nothing application all follow the algorithm.
- A plugin that returns a foreign line, a foreign currency, a target the stage cannot use, a promotion it was not asked about, a double activation, or a code that was not presented fails the quote with `promotions.invalid_rule_result`. Line ids such as `0`, `1`, and `10` stay values in lists, and the discounts stay on those lines.
- Catalog starts one session per run; cart and delivery reuse it. The same `QuoteInput` run again reloads. The `WeakMap` does not keep the input alive. `explain` drops any earlier session first. Evaluation does not write and takes time from `context.at`.
- The load query applies the window, currency, and channel filters. Code rejections stay in presentation order, with the first activator key owning a code. A paused, other-channel, other-currency, or exhausted coupon is `promotion_not_active` or `coupon_exhausted`, not `unknown_code`.
- `ExplainPromotions`, `PromotionExplanation`, `PromotionVerdict`, and `VerdictKind` are public `#[Api(since: '0.1')]` types, and `PromotionDefinitions::explain` delegates to the internal explainer.

`pest --testsuite=Promotions`: 302 passed. Architecture gates: 13 passed. PHPStan (level max) on the P5.6 files: no errors.

Findings: none

Verdict: pass