# 03-promotions-pricing-plugins

- Status: completed
- Method: searchDeep
- Started (UTC): 2026-10-08T14:02:03.933Z
- Finished (UTC): 2026-10-08T14:05:29.532Z
- Transport: local perplexity-web-mcp daemon client via shell

## Question

Research deterministic pricing, promotions, coupons, and extension architecture for a reusable PHP/Laravel commerce package. Compare primary documentation/source for Sylius promotion rules/actions/processors and plugins, Lunar discounts/cart pipelines/pricing, Bagisto cart rules and Concord model proxies, Adobe Commerce sales rules and extension points, Shopify discount combinations/functions/allocations. Analyze price context (currency/channel/customer/quantity/time), integer or decimal money and rounding allocation, priority/stacking/exclusivity, free shipping vs item/order discounts, usage-limit races, coupon reservations and redemption lifecycle, immutable order snapshots and refunds. Compare typed contracts/registries/pipelines/domain events versus arbitrary Eloquent model replacement and synchronous callbacks. Give explicit invariants, reentrancy/ordering hazards, transaction boundaries, plugin compatibility/versioning, and pragmatic module boundaries. Supply exact official URLs/public source links for factual claims and clearly label design proposals.

## Unverified Perplexity answer

> Это сырой текст DOM-извлечения Perplexity. Таблицы, формулы и некоторые заголовки могли потеряться; отсутствие строки не означает отсутствие раздела в исходном ответе. Формулировки «verified/fact» внутри ответа принадлежат Perplexity. Собственная проверка — в [verified-findings.md](verified-findings.md). Список sources, возвращённый клиентом, содержит по 10 элементов и не считается полным списком всех использованных страниц.

## Recommendation

Build the reusable package around a deterministic pricing kernel, not around mutable Eloquent carts or an open-ended callback system:
- Resolve an explicit, versioned price context.
- Produce promotion candidates through typed rules and actions.
- Select candidates through a centrally owned stacking policy.
- Allocate money through one rounding implementation.
- Return an immutable calculation result.
- Reserve and redeem limited coupons through transactional application services.
- Freeze the accepted result into the order; refund against its original allocations.
The useful precedents are complementary: Sylius separates eligibility rules from actions and recalculates promotions; Lunar offers Laravel-native calculation pipelines; Bagisto demonstrates both the convenience and conflict risks of model replacement; Adobe exposes a broad interception system; Shopify separates discount intentions, combination policy, and actual allocations. None of the documentation examined establishes a complete, portable concurrency-safe coupon lifecycle for your package.
Below, “Verified” describes documentation or source evidence. “Proposal” describes the architecture I recommend—not a claim that an existing platform implements it.

## 1. Evidence scope and version caveats

This is a primary-documentation comparison, with a limited public-source inspection—not a complete executable audit of five engines.
Important boundaries:
- Lunar findings below use the accessible 1.x documentation. Attempts to retrieve the 2.x discount pages failed; do not treat the findings as verification of Lunar 2.x.
- Shopify’s retrieved latest developer documentation identified itself as API version 2026-10. Pin that version rather than relying on a moving latest URL.
- Sylius’s official documentation establishes processor behavior at a high level. GitHub search located its processor at commit 39313695548c709756ee9073bf309fa4ae89365a, but full file retrieval did not expose readable contents. Consequently, the precise exclusive-selection loop and equal-priority tie-breaker are not source-verified here.
- Public configuration fields such as usage_limit or “uses per customer” do not prove safe concurrent enforcement. That requires inspection of write paths, locking, database constraints, and lifecycle tests.
Exact official URLs are supplied beside the relevant sections. Public source entry points are distinguished from files whose contents were actually verified.

## 2. Platform comparison


## Sylius: rules, actions, filters, and recalculation

Verified:
- Cart promotions have usage limits, validity periods, priority, and exclusivity.
- Higher numeric priority runs before lower priority—the opposite direction from Bagisto and Adobe.
- Rules determine eligibility; actions perform discounts.
- Actions include order-fixed, order-percentage, item-fixed, item-percentage, and shipping-percentage discounts.
- Filters restrict which items an action affects.
- The documented PromotionProcessor reverts existing promotions, checks eligibility, and applies eligible promotions.
Custom eligibility implements:
```
RuleCheckerInterface::isEligible( PromotionSubjectInterface $subject, array $configuration ): bool;
```
The service is registered with a typed identifier through the sylius.promotion_rule_checker tag. Custom actions are registered separately through sylius.promotion_action.
The official action tutorial uses ProportionalIntegerDistributorInterface and UnitsPromotionAdjustmentsApplicatorInterface, demonstrating integer distribution and unit-level adjustments as explicit collaborators.
A useful documentation-audit finding: the “cheapest item” tutorial calculates a discount equal to the cheapest unit’s price, then distributes that amount proportionally across item totals. That is not the same allocation as attaching the entire discount to the cheapest unit. Its concluding text also mentions a $10 threshold that the displayed action does not implement. Treat the example as extension-mechanism guidance, not a production financial specification.
Proposal:
Borrow the rule/action separation and recalculation discipline. Improve it by making actions return candidate adjustments rather than mutate an order directly. Keep action targeting separate from eligibility: “cart contains a product” does not necessarily mean “discount only that product.”
Official documentation:
- https://docs.sylius.com/the-book/carts-and-orders/cart-promotions
- https://docs.sylius.com/how-to-add-a-custom-cart-promotion-rule
- https://docs.sylius.com/how-to-add-a-custom-cart-promotion-action
Located public source, not fully read:
- https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Component/Promotion/Processor/PromotionProcessor.php

## Lunar: an excellent Laravel integration model, but mutation needs boundaries

Lunar’s documented cart pipeline is:
```
CalculateLines → ApplyShipping → ApplyDiscounts → CalculateTax → Calculate
```
It is configured in config/lunar/cart.php, runs top-to-bottom, and permits custom pipeline stages and replaceable cart actions. Its example stage receives a cart and a $next closure.
Custom discount types extend AbstractDiscountType, register through Discounts::addType(), and expose apply(Cart $cart): Cart. Admin integration uses a separate LunarPanelDiscountInterface.
Lunar stores product prices as integers and provides pricing pipelines through config/lunar/pricing.php. Carts have one currency, dynamically calculated prices, and persisted order prices after conversion to an order.
The current 1.x cart documentation explicitly describes an important retry problem: order creation consumes a discount when a draft order exists; repeated checkout could otherwise exhaust its own coupon and reprice without it. Lunar documents tracking the draft order’s consumed discounts so the cart does not count its own consumption against itself. This is evidence of retry-aware consumption—not proof of global concurrent usage-limit safety.
Use Lunar-like configuration and Laravel service-provider ergonomics, but make calculation stages operate on explicit calculation data rather than a live Eloquent aggregate. Keep draft-order creation, reservation, and redemption distinct instead of overloading “order exists” as the consumption boundary.
- https://docs.lunarphp.com/1.x/extending/carts
- https://docs.lunarphp.com/1.x/extending/discounts
- https://docs.lunarphp.com/1.x/reference/products
- https://docs.lunarphp.com/1.x/reference/carts
Public source entry point:
- https://github.com/lunarphp/lunar/blob/1.x/packages/core/src/Pipelines/Cart/ApplyDiscounts.php

## Bagisto: explicit merchandising rules, replacement conflicts

Bagisto cart rules expose channel, customer group, coupon gating, date range, conditions, priority, discount actions, shipping application, and free shipping. Lower priority numbers run first, with 0 documented as highest priority. End Other Rules stops subsequent rules; its API documentation identifies percentage, item-fixed, cart-fixed, and buy-X-get-Y action types.
Bagisto’s Concord model system consists of:
- A contract.
- An Eloquent implementation.
- A proxy resolving the registered implementation.
- A module provider registering the model.
The official documentation states that only one implementation exists per contract and the last registered module wins. Two packages replacing the same model therefore conflict. Its example contract is an empty marker interface, which identifies a model family but does not specify behavioral guarantees.
Allow host-controlled model substitution as an integration escape hatch, not the primary plugin composition mechanism. A pricing plugin should register a price resolver or promotion action—not replace Cart, Order, or Product.
- https://docs.bagisto.com/marketing/cart-rules
- https://api-docs.bagisto.com/api/graphql-api/admin/marketing/promotions/cart-rules/
- https://devdocs.bagisto.com/package-development/models.html

## Adobe Commerce: powerful interception, broad ordering surface

Adobe cart price rules apply automatically or through coupons. Lower numeric priority executes first. Its documentation recommends distinct priorities and describes equal-priority evaluation in rule-ID order. “Discard Subsequent Rules” is described in terms of preventing further pricing rules on a qualifying product—not necessarily a universal cart-wide exclusivity flag.
Adobe separates “Apply to Shipping Amount” from product discount settings. This reinforces that merchandise and shipping are separate discount bases.
Extensions can intercept public methods with before, after, or around plugins. Documented restrictions include final classes/methods, non-public methods, static methods, constructors/destructors, and certain non-interceptable objects. Events and observers provide another extension mechanism.
Do not reproduce general-purpose method interception as your financial extension API. It is useful for framework integration, but the set of methods a plugin can intercept is much wider than the set of financial policies you can safely promise to support.
- https://experienceleague.adobe.com/en/docs/commerce-admin/marketing/promotions/cart-rules/price-rules-cart-create
- https://experienceleague.adobe.com/en/docs/commerce-admin/marketing/promotions/cart-rules/price-rules-cart-coupon
- https://developer.adobe.com/commerce/php/development/components/plugins
- https://developer.adobe.com/commerce/php/development/components/events-and-observers/
Public source entry points for a follow-up implementation audit:
- https://github.com/magento/magento2/tree/2.4-develop/app/code/Magento/SalesRule
- https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/SalesRule/Model/Validator.php
- https://github.com/magento/magento2/blob/2.4-develop/app/code/Magento/SalesRule/Model/RulesApplier.php
These moving-branch links are navigation aids, not commit-pinned evidence of concurrency behavior.

## Shopify: candidate generation, centrally owned combinations, actual allocations

The Discount Function API supports product, order, and shipping discount classes in one function. Functions run concurrently and have no knowledge of each other; their outputs combine according to the discount node’s stacking configuration. The retrieved documentation permits up to 25 activated discount functions.
Shopify documents:
- Mutual combination permission.
- Product discounts first, then order discounts on the revised subtotal, then shipping discounts.
- Multiple percentage order discounts calculated on the same post-product-discount subtotal, rather than compounded sequentially.
- Best-discount selection when discounts cannot combine.
- No combination of multiple shipping discounts on the same order.
- Additional eligibility and plan restrictions for some combinations.
DiscountApplication expresses intent; DiscountAllocation records the actual amount applied to a merchandise or shipping line, including shop and presentment currency amounts.
This is the strongest conceptual model to borrow: plugins generate candidates; the engine owns compatibility, selection, allocation, and totals. Do not promise Shopify-equivalent selection or rounding—the public contract does not specify every internal algorithm.
- https://shopify.dev/docs/api/functions/2026-10/discount
- https://help.shopify.com/en/manual/discounts/discount-combinations
- https://shopify.dev/docs/api/admin-graphql/2026-10/objects/DiscountAllocation

## 3. Price context and money model

Everything in this section is a design proposal.

## Explicit context

Use a value object, not ambient request state:
```
PriceContext tenant/store channel currency + monetary scale customer identity + segment snapshot price-list/customer-agreement identifiers quantity + quantity unit/scale evaluation instant tax jurisdiction + tax-inclusion mode shipping destination + delivery selection coupon identities price/rule/tax/shipping dataset revisions engine/policy/plugin versions
```
The complete calculation input also contains stable line identifiers, product/variant facts, and quantities.
Invariants:
- Identical normalized input, dependency snapshots, and engine versions produce identical results.
- One evaluation instant applies to the entire calculation; rules never call now() independently.
- Customer segments and historical eligibility facts are resolved before evaluation, not lazily re-queried by each rule.
- Currency mismatches fail explicitly.
- Missing currency prices do not silently become zero or use an unrecorded FX rate.
- Price-tier selection defines whether quantity aggregates by line, variant, product, or cart.
- Promotion intervals have explicit boundary semantics—prefer [starts_at, ends_at)—and merchant-local schedules are converted to unambiguous instants.
A cache key must include every price-affecting input or its revision. A cart fingerprint alone is insufficient if prices, customer groups, rules, or plugin behavior change.

## Integer settlement money; exact intermediate arithmetic

Recommended default:
- Settled amounts: integer minor units plus currency/scale metadata.
- Rates, FX, fractional quantities, and intermediate calculations: exact decimal or rational arithmetic.
- No binary floats in the financial kernel.
- No universal assumption that every currency has two decimal places.
- One explicit rounding policy per calculation stage.
Integer and decimal storage are not inherently opposing architectures. Exact fixed-scale decimal amounts can work; the dangerous combination is implicit scale, float arithmetic, and scattered rounding.
Distinguish:
```
raw unit price extended unrounded line amount rounded line amount discount allocation tax amount settlement total
```
Do not assume rounded unit price × quantity equals round(raw unit price × quantity). Choose one policy and make it observable.

## Allocation conservation

For a fixed discount DDD over eligible weights wiw_iwi​:
Allocate integer floors, then distribute residual minor units by descending fractional remainder, with a stable target-ID tie-breaker.
Required properties:
- ∑iai=D\sum_i a_i = D∑i​ai​=D.
- 0≤ai≤0 \le a_i \le0≤ai​≤ the target’s remaining discountable amount.
- Zero-value targets receive no proportional allocation.
- Empty or zero-weight target sets cannot cause division by zero.
- If caps bind, redistribute the remainder over uncapped targets.
- Allocation does not depend on database return order.
For a 100-minor-unit discount across three equally weighted targets, allocations are 34, 33, 33; the stable tie-breaker decides who receives 34.
Store the result. Do not rerun allocation during refunds.

## 4. Priority, stacking, exclusivity, and shipping


## Priority is not a stacking policy

The existing platforms demonstrate why a bare integer is insufficient: Sylius orders higher numbers first; Bagisto and Adobe order lower numbers first; Shopify additionally defines class ordering and shared percentage bases.
Define these separately:
For v1, use priority-first selection with explicit compatibility rules. “Maximum savings” requires a much stronger contract: candidate combinations, caps, shipping dependencies, tax effects, and deterministic tie resolution. Do not quietly mix greedy priority selection with an advertised best-price guarantee.

## Freeze eligibility bases

Choose named bases such as:
```
merchandise_before_promotions merchandise_after_product_promotions merchandise_after_order_promotions
```
A threshold must reference one of them.
Otherwise a “$100 minimum” promotion can invalidate itself after discounting the cart. Avoid repeated evaluation until convergence; the system can oscillate or become dependent on iteration limits.

## Free shipping is a shipping adjustment

Represent merchandise and shipping targets separately.
Free shipping should retain:
- The original shipping quote.
- The selected service and shipment scope.
- The shipping discount allocation.
- Any cap or excluded surcharge.
- Tax treatment supplied by the tax policy.
Specify whether “free shipping” means all delivery groups, one shipment, cheapest eligible service, or a capped contribution. It must not accidentally mean “zero out every shipping-related fee.”
Avoid a circular policy:
```
discounted subtotal determines shipping shipping determines promotion eligibility promotion changes discounted subtotal
```
Resolve this through an explicit phase graph and frozen bases, or reject the configuration.

## 5. Coupon limits, reservations, and redemption


## Distinguish lifecycle states

```
available → reserved → redeemed reserved → released reserved → expired redeemed → reversal entry, if business policy permits
```
Coupon entry and cart preview do not consume capacity.
Use:
- Coupon: identity and policy.
- Reservation: capacity held for a checkout attempt.
- Redemption: durable consumption tied to an accepted order.
- Reversal: explicit compensating ledger entry.
For limited promotions, reserve both the promotion budget and the coupon budget where applicable. A per-coupon lock does not protect a shared promotion-wide limit.

## Concurrency invariant

For each applicable budget:
Enforce this inside the database transaction—not through a preliminary SELECT count(*).
A practical implementation maintains transactional counters and a reservation ledger:
- Lock or conditionally update the relevant budget rows.
- Check global and customer-scoped capacity.
- Create the reservation under a unique checkout/idempotency key.
- Increment reserved capacity.
- Commit all applicable budgets together.
Acquire locks in one globally consistent order, including when several coupons are present. This reduces deadlock hazards; still support bounded retries.
A uniqueness constraint alone prevents duplicate consumption, but does not enforce a shared numeric limit.

## Idempotency and identity

Define:
- One reservation per checkout attempt and coupon.
- One redemption per reservation/order according to the chosen consumption semantics.
- A payload hash alongside an idempotency key.
- Explicit rejection when a reused key carries different financial input.
For customer limits, define the identity policy. Anonymous users cannot receive a strong “once per person” guarantee merely from a cookie or supplied email.

## Reservation expiry and payment uncertainty

Do not release capacity solely because a wall-clock TTL elapsed while payment may still succeed.
A reservation can expire for new use while the checkout enters reconciliation. Decide what happens when a successful payment arrives after its reservation was released:
- Honor the frozen price through a bounded exception policy.
- Recover capacity before accepting the order.
- Void/refund the payment if acceptance cannot complete.
Never silently reprice an already authorized payment.
Failed payment retries should reuse the accepted quote/reservation when still valid. Cancellation or refund restores usage only if the policy explicitly says so; deleting an order is not a financial reversal.

## 6. Transaction boundaries and order snapshots


## Recommended boundaries

Keep external network calls outside database locks.
Persist domain state and outbox messages in the same transaction. Dispatching “after commit” prevents premature notification but, without a durable outbox, does not itself close the process-crash gap between commit and dispatch.
If coupon, inventory, and order records share one database, a coordinated local transaction is pragmatic. If adapters use separate databases or external systems, state explicitly that the operation becomes a saga with compensation—not an atomic transaction.

## Immutable financial snapshot

Freeze the accepted financial result, including:
- Product description/SKU and selected options.
- Quantities and stable allocation targets.
- Unit and extended amounts.
- Currency and scale.
- Merchandise and shipping allocations.
- Promotion/coupon identity, configuration revision, and display label.
- Tax bases, rates, amounts, jurisdiction, and rounding policy.
- Shipping quote and service.
- FX source/rate if conversion occurred.
- Calculation input fingerprint.
- Engine and plugin versions.
- Reconciliation totals.
Keep mutable fulfillment and payment state separate from the immutable financial snapshot.
An order edit creates a new revision or explicit delta. It does not overwrite the original accepted amounts.

## Refunds use original economics

A partial refund uses the original unit/line discount and tax allocations—not current prices or current promotion eligibility.
Required invariants:
- Cumulative refunded quantity does not exceed purchased quantity.
- Cumulative refunded amount does not exceed the relevant refundable balance.
- Full refund conserves the original refundable total exactly.
- Refund retries do not duplicate provider refunds or ledger entries.
- Shipping refunds follow their own explicit policy.
- Any promotion clawback is an explicit adjustment policy, not an accidental recalculation.
For buy-X-get-Y, preserve unit-level entitlement/allocation or a deterministic equivalent. Otherwise returning a paid unit versus a free unit becomes ambiguous.

## 7. Extension architecture and hazards


## Public contracts

A compact contract surface could be:
```
interface PriceResolver { public function resolve( PurchasableSnapshot $item, PriceContext $context, ): ResolvedPrice; } interface PromotionRule { public function evaluate( EvaluationSnapshot $input, RuleConfiguration $configuration, ): EligibilityResult; } interface PromotionAction { public function propose( EvaluationSnapshot $input, ActionConfiguration $configuration, ): CandidateSet; } interface CombinationPolicy { public function select( CandidateSet $candidates, EvaluationSnapshot $input, ): SelectedCandidates; } interface MoneyAllocator { public function allocate( Money $amount, AllocationTargets $targets, ): AllocationSet; }
```
Use namespaced registry IDs such as vendor.loyalty.points.v1, configuration schemas, and explicit configuration versions.
Return structured reasons for eligibility and rejection. This supports admin diagnostics without making a plugin’s private class names part of persisted business data.

## Ordered stages, not unrestricted middleware

Use named stages with declared inputs and outputs:
```
ResolveFacts → ResolvePrices → ProductPromotions → OrderPromotions → ResolveShipping → ShippingPromotions → CalculateTax → Reconcile
```
The tax stage may need a different arrangement for a jurisdiction or pricing mode; make that an explicit policy, not an arbitrary plugin reorder.
Allow extensions to declare dependencies such as after: resolve_prices, then validate the graph at boot:
- Reject cycles.
- Reject duplicate stage ownership.
- Reject missing dependencies.
- Reject ambiguous exclusive replacements.
- Produce an inspectable final plan.

## Reentrancy and ordering hazards


## Events versus decisions

Use synchronous typed collaborators for decisions required to complete a calculation or transaction.
Use durable domain events for facts that already occurred:
```
QuoteAccepted CouponReserved OrderPlaced PaymentCaptured RefundCompleted
```
Events should carry IDs and immutable payload snapshots, not live Eloquent objects.
Do not ask arbitrary event listeners to decide final prices, veto a transaction after some side effects, or modify an accepted order. Mandatory business validation belongs in the command’s explicit dependency graph.

## Restrained Eloquent replacement

Bagisto’s documented single-winner replacement behavior is a concrete warning about composability.
For your package:
- Prefer additional tables, relations, metadata schemas, and adapter contracts.
- Permit model substitution only through explicit host configuration.
- Require compatible table, key, casts, relations, morph aliases, and financial behavior.
- Never let plugins compete silently through service-provider order.
- Keep model objects out of the pricing kernel’s public contracts.
Typed PHP interfaces help, but behavioral compatibility still requires conformance tests.

## 8. Plugin versioning and pragmatic module boundaries


## Compatibility is more than Composer constraints

A plugin manifest should declare:
```
package/core version range PHP/Laravel version range contract API version configuration schema versions provided registry IDs required capabilities stage dependencies exclusive replacements database migration version
```
Version the financial semantics as well as method signatures. A patch that changes tie-breaking, rounding, or stacking may alter checkout totals even without a PHP API break.
Keep historical configuration readers or upgrade configurations explicitly. Removing a plugin must not make old order snapshots or refund allocations unreadable.
Test at least:
- Each supported dependency range.
- Known interacting plugin pairs.
- Provider-registration order independence.
- Repeated calculation.
- Concurrent reservation.
- Failed-payment retries.
- Partial-refund conservation.

## Module ownership

A modular monolith with one database is sufficient initially.
Separate ownership before separating deployment. Do not create a distributed service for every row in this table.

## 9. Acceptance criteria before shipping v1

Treat these as executable requirements:
- Recalculation never accumulates adjustments from previous runs.
- Identical versioned inputs produce byte-equivalent normalized financial results.
- Equal-priority results have stable tie-breakers.
- Every discount has a declared basis, target, cap, and exclusivity scope.
- Allocations conserve the discount exactly.
- Merchandise and shipping never become negative through promotions.
- Taxes and totals reconcile under the selected tax/rounding policy.
- Preview never reserves or redeems a coupon.
- Concurrent checkout cannot exceed global or customer usage capacity.
- Retry cannot create duplicate orders, reservations, redemptions, captures, or refunds.
- Accepted order amounts survive product, promotion, plugin, and FX changes.
- Full refund conserves the original refundable amount.
- Plugin conflicts fail at boot or installation—not during checkout.
- Calculation performs no external side effects.
- Transactional state changes and their durable event records commit together.
For a pragmatic first release, implement priority-first stacking, explicit product/order/shipping scopes, exact money allocation, limited-coupon reservations, immutable order allocations, and typed extension registries. Defer arbitrary method interception, plugin-driven model replacement, distributed checkout, and “globally optimal discount combination” promises until you have a concrete use case and adversarial tests for their semantics.

## Sources returned by Perplexity (not automatically verified)

1. [docs.sylius](https://docs.sylius.com/the-book/carts-and-orders/cart-promotions)
2. [docs.sylius](https://docs.sylius.com/how-to-add-a-custom-cart-promotion-action)
3. [https://docs.sylius.com/how-to-add-a-custom-cart-promotion-rule](https://docs.sylius.com/how-to-add-a-custom-cart-promotion-rule)
4. [https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Component/Promotion/Processor/PromotionProcessor.php](https://github.com/Sylius/Sylius/blob/39313695548c709756ee9073bf309fa4ae89365a/src/Sylius/Component/Promotion/Processor/PromotionProcessor.php)
5. [docs.lunarphp](https://docs.lunarphp.com/1.x/extending/carts)
6. [lunarphp](https://docs.lunarphp.com/1.x/extending/discounts)
7. [lunarphp](https://docs.lunarphp.com/1.x/reference/carts)
8. [https://docs.lunarphp.com/1.x/reference/products](https://docs.lunarphp.com/1.x/reference/products)
9. [https://github.com/lunarphp/lunar/blob/1.x/packages/core/src/Pipelines/Cart/ApplyDiscounts.php](https://github.com/lunarphp/lunar/blob/1.x/packages/core/src/Pipelines/Cart/ApplyDiscounts.php)
10. [devdocs.bagisto](https://devdocs.bagisto.com/package-development/models.html)
