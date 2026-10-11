The P8.5 snapshot matches its generator on PHP 8.4 (689 public types, no `Internal` entries), and the platform section matches the code. The snapshot still misstates signatures, and `docs:reference --check` fails.

## Findings

**1. High** — `tests/Arch/Support/ApiManifest.php:154` and `packages/shipping/resources/contracts/php-api.json:771`

`getDefaultValueConstantName()` is written through even when that constant does not exist, so global `PHP_INT_MIN` / `PHP_INT_MAX` are recorded as namespace constants that are not defined. The same strings are in `packages/promotions/resources/contracts/php-api.json:983` and `docs/reference/api/shipping.md:811`.

REPRODUCTION (PHP 8.4.26):

```php
$p = (new ReflectionMethod(Commerz\Shipping\Rates\Data\RateParams::class, 'int'))->getParameters()[1];
$p->getDefaultValueConstantName(); // "Commerz\Shipping\Rates\Data\PHP_INT_MIN"
defined($p->getDefaultValueConstantName()); // false
$p->getDefaultValue(); // -9223372036854775807-1  (the real PHP_INT_MIN)
```

Source is `int $min = PHP_INT_MIN` (`packages/shipping/src/Rates/Data/RateParams.php:88`). The same phantom name is on `RateParams::nullableInt` and `RuleParams::int` / `nullableInt` (8 parameters).

**2. High** — `tests/Arch/Support/ApiManifest.php:105` and `packages/testkit/resources/contracts/php-api.json:167`

Property lines keep read visibility, `static`, and `readonly` only, so `public private(set)` is stored as a publicly writable `public array` and the initializer is dropped. Changing `private(set)` to public-set, or `= []` to another default, does not change the manifest.

REPRODUCTION: `packages/testkit/src/Fakes/FakeStock.php:44` is `public private(set) array $reserved = []`. The manifest says `"reserved": "public array $reserved"`. `ReflectionProperty::isPrivateSet()` is true and `isReadOnly()` is false. Same for the other eight fake logs: `FakeStock::$released/$consumed/$restocked`, `FakeDelivery::$requests/$bookings`, `FakePrices::$requests`, `FakePromotionUsage::$consumed/$restored`.

**3. High** — `tests/Arch/Support/ApiManifest.php:148` and `tests/Arch/Support/ApiSurface.php:129`

Declared `self` is recovered from the AST only for a return type that is exactly `self` or `?self`. Parameter types use reflection. PHP 8.5 `ReflectionNamedType::getName()` returns the class name for `self` ([php/php-src#21284](https://github.com/php/php-src/issues/21284)), and every package requires `php: ^8.4`, which includes 8.5. Returns are safe here: 76 `self` returns, 0 start-line mismatches with the parser. The probe in `tests/Arch/ApiManifestTest.php:32` only locks returns.

REPRODUCTION: on PHP 8.4 the manifest has `plus(self $other): self` (`packages/core/resources/contracts/php-api.json`, `Commerz\Money\Money`). On PHP 8.5 the same method is `plus(\Commerz\Money\Money $other): self`, so `api:check` disagrees across supported versions. Fifteen public parameters are in this shape (`Money`, `Quantity`, `Measure`, `Currency`, `EntityId`, `Ref`, `IdempotencyKey`, `AttributeStatus::canBecome`).

**4. Medium** — `tests/Arch/Support/ApiManifest.php:166` and `packages/core/resources/contracts/php-api.json:333`

Method signatures record `protected`, `static`, and `abstract`, and never `final`. Adding or removing `final` on a method of a non-final type does not change the manifest.

REPRODUCTION: `EntityId::__construct` is `final` (`packages/core/src/Identity/EntityId.php:17`) and the manifest says `"__construct": "__construct(string $value)"`. `EntityId::equals` is not final (`EntityId.php:38`, manifest line 335); marking it `final` blocks overrides on every id type and still leaves `equals(self $other): bool`. The same omission covers the five `final` methods on abstract `Ref` (constructor, `of`, `fromString`, `equals`, `__toString`).

**5. Medium** — `docs/reference/api/sales.md:291`

The generated page is behind the docblock, so the item’s own check fails.

REPRODUCTION:

```text
php8.4 scripts/generate-api-reference.php --check
Stale API reference, run composer docs:reference:
  docs/reference/api/sales.md
exit 1
```

Only that file differs, by one line. `SetCartCustomer`’s first paragraph (`packages/sales/src/Carts/Data/SetCartCustomer.php:12-13`) continues after “see.”; the page stops there. The other eight reference pages match a fresh render. The php-api.json files match `ApiManifest::build()` byte for byte on PHP 8.4.26.

**6. Low** — `tests/Arch/Support/ApiManifest.php:82` and `packages/inventory/resources/contracts/php-api.json:831`

`getParentClass()` is the only `extends`. An interface’s parents come from `getInterfaceNames()` and are stored under `implements`, so `extends` is null. `Reservations` extends `StockReservations` (`packages/inventory/src/Reservations/Contracts/Reservations.php:29`) but the snapshot has `"implements": ["Commerz\\Ports\\Stock\\Contracts\\StockReservations"]` and no `release` method (that method stays only on the core interface). The markdown renderer happens to print `extends` (`docs/reference/api/inventory.md`). A checker that walks `extends` never sees this parent. It is the only interface with a parent.

## What held up

Platform blocks match the packages: Composer providers, `mergeConfigFrom` keys (`commerz`, `commerz-catalog`, …), the nine Artisan names (including multiline `commerz:outbox:relay`), plugin ids, ownership nouns, and all 14 `KIND` constants. `testkit` is an empty platform object. `full` has no manifest. No public type uses a trait. No public constant or property inherited from an `Internal` parent is missing. DNF prints as `(\A&\B)|\C|null`. Reference anchors are unique, and `<` / `{{` outside code spans are escaped. `composer.json` scripts and the lint job call `api:check` and `docs:reference -- --check`. The manifest wrapper shells out with `PHP_BINARY`. `--check` does not write.

`scripts/php84` could not be used here (`ln` into `build/php84/bin/php` was denied). The checks above ran with `/usr/bin/php8.4`.

Verdict: fail

Residual risk: command option lists are names only, so a signature flag change is invisible by design. No property uses hooks or explicit `protected(set)`, no default is `new Foo(...)` with arguments, and no return is `self|null`, so those paths are untested. `PluginRegistrar::__construct` really type-hints `Commerz\Plugins\Internal\PluginDeclarations`; the manifest records that rather than hiding it. Inline code is not wrapped in `v-pre`, and no current summary contains `{{` inside backticks. The double-generation hash runs only on the write path, on one PHP build, so it does not catch the 8.5 `self` parameter drift.
