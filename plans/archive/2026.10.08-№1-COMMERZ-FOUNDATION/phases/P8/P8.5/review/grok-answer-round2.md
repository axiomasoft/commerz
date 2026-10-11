The correction closes all six round-1 findings on the eight manifests, and the checks match the code. No new misstatement showed up.

**1. Global constant defaults.** All eight parameters are `\PHP_INT_MIN` / `\PHP_INT_MAX`: `RateParams::int`, `RateParams::nullableInt`, `RuleParams::int`, `RuleParams::nullableInt`, in both `php-api.json` and the shipping and promotions reference pages.

**2. Asymmetric properties.** All nine `public private(set) array … = []` logs are stored that way (`FakeStock`, `FakeDelivery`, `FakePrices`, `FakePromotionUsage`). Readonly properties stay `readonly` and do not gain an implicit `protected(set)`.

**3. `self`.** Every public parameter and return whose source type contains `self` is mapped by `declaredSelf()`, including `Money`, `Quantity`, `Measure`, `Currency`, `EntityId`, `Ref`, `IdempotencyKey`, and `AttributeStatus::canBecome`. `asSelf()` turns `\Declaring\Class` back into `self` and leaves a longer class name alone.

**4. `final`.** `EntityId::__construct` and the five `final` methods on `Ref` are marked `final` in `packages/core/resources/contracts/php-api.json`. No other public method disagrees with that rule.

**5. Reference page.** `docs/reference/api/sales.md` includes the full first paragraph of `SetCartCustomer`.

**6. Interface parents.** `Reservations` is the only interface with a parent, and `extends` is exactly `["Commerz\\Ports\\Stock\\Contracts\\StockReservations"]`. `docs/reference/api/inventory.md` prints that parent.

`scripts/php84` could not refresh its shim (`ln` into `build/php84/bin/php` was denied). With `/usr/bin/php8.4`: `ApiManifestTest` passed (4 tests), `generate-api-manifest.php --check` passed, and `generate-api-reference.php --check` printed `API reference is up to date.`

Verdict: pass

Residual risk: there is no PHP 8.5 binary here, so version independence rests on the AST path, which the audit saw wired for every public `self` in the surface. Union order still follows reflection (`string|int|bool|array` is stored as `array|string|int|bool`), which has been stable since union types landed and which the probe locks. A class `implements` list is every interface from `getInterfaceNames()`, ancestors included (`PackageTestCase` lists the PHPUnit interfaces). The same call fills an interface’s `extends`, so a grandparent would be listed too; the only parented interface has one direct parent. Property types do not go through the `self` recovery, and no public property is typed `self`. No public type uses a trait. Class-constant defaults stay in reflection’s spelling, without a leading `\`. Promoted defaults stay on the constructor; the only `new` default is zero-argument `new UsageLimits`, recorded as `new \Commerz\Promotions\Definitions\Data\UsageLimits`. Command options remain names only. Hooks, `new` with arguments, and `self|null` do not appear in the surface.
