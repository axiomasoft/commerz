The CRM subset does sell, tax, place, pay, and complete without the shop packages. The two files pass together on `commerz_review2_test`: 3 tests, 34 assertions.

## Findings

**Low.** `tests/EndToEnd/CrmScenarioTest.php:156`. The absence check treats a table as foreign when its name starts with `prefix + noun`, so `cmz_categories` is invisible, while `SchemaOwnership` counts it because `Str::plural('category')` is `categories`.

REPRODUCTION:

```text
MISSED foreign tables:
cmz_categories
FALSE POSITIVES on installed tables:
(none)
plural(category)=categories
starts categories: no
starts category_products: yes
```

A full catalog migration is still caught: the same migration creates `cmz_category_products`, and `cmz_products`, `cmz_variants`, `cmz_options`, `cmz_attributes`, and `cmz_listings` match the prefix. Installed core, pricing, and sales tables do not match the absent nouns. The check is not vacuous: it requires `cmz_orders` and `cmz_price_lists` in that same listing (`CrmScenarioTest.php:165`).

**Low.** `tests/EndToEnd/CrmScenarioTest.php:106` and `tests/EndToEnd/Fixtures/CrmCustomers.php:19`. Quote and place both price from the cart groups written by `SetCartCustomer`; `CustomerDirectory` supplies email, name, and `taxExempt` (`OrderWriter.php:79`, `TaxStage.php:57`, `DatabasePriceResolver.php:88`). The test puts `b2b` in both places and asserts them as one tuple, so a directory group of `retail` with the same email still passes.

REPRODUCTION (snippet; the file cannot be added here):

```php
Sales::carts()->setCustomer(new SetCartCustomer(
    $draft->id,
    CustomerRef::fromString(CrmCustomers::ACME),
    [],
));
$quote = Sales::carts()->quote($draft->id);
[$consulting, $audit] = $quote->lines;

expect($audit->unitPrice)->toEqual(Money::of('3600.00', 'RUB'));
```

That expectation fails. With empty cart groups the `b2b` contract row is skipped and `audit` falls back to the tariff `4000.00` (`priceSource` `sellable`). Place still stores `billing@acme.test` / `ACME LLC` and groups `[]`. Quote and place stay in agreement with each other. This matches `sales-design` (directory find is the email/name snapshot) and the logged P8.3 note.

## What holds

The scenario’s `beforeEach` runs `migrate:fresh` on only `Commerz`, `Pricing`, and `Sales`. Testbench package discovery ignores every package (`dont-discover: *`), and sales, pricing, and core do not reference the shop namespaces. The green run quotes two services with no address (`default_zone=ru`), places a draft (`origin` draft, number `CRM-…`), captures a manual payment, fulfills, and reaches `Completed`. Ports are `UnlimitedStock`, `NullPromotionUsage`, `NoDelivery`, and `DatabasePriceResolver`. `commerz:doctor` exits 0. `priceSource` is `sellable` for consulting and `price_list:contract` for audit.

The mixed host, in the same process after that wipe, rebuilds the full schema through `RefreshDatabase` and passes: one cart, `variant` then `crm.service`, total `2550.00` with included VAT `425.00`, and a reservation whose only line is the variant (available 4).

`PackageTestCase` clears `RefreshDatabaseState::$migrated` when the provider list changes, so the mixed file remigrates. Inside the CRM file, the second test’s own `migrate:fresh` runs after `CommittedDatabase::truncate`.

## Questions

`CustomerProfile::$groups` is documented as driving prices, and `config/commerz.php` describes `commerz.customers` as the host’s customer groups. No production caller reads `profile.groups`. Is the cart the permanent source of price groups for a CRM host?

## Verdict: pass

Residual risk: `cmz_categories` alone would not fail the absence check. The mixed cart’s goods are `2250.00` with the service and `900.00` without, both under the courier `freeAbove` of `5000.00`, so the `300.00` charge does not show whether the service counts toward that threshold (sales already treats the whole cart value as the threshold and excludes the service from the booked parcel). `paymentDueAt` on the draft is not asserted; placement sets it only for `CartKind::Cart`. A later test that only calls `migrate` after this file’s truncate would see an empty `cmz_schema_state` fingerprint row; in the EndToEnd directory order, `ReverseBootTest` `migrate:fresh` runs before `ShopScenarioTest`. The full EndToEnd suite was not run.
