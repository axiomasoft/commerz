**Verdict: fail.** The reverse boot is real and the port matrix holds, but `bootInReverseOrder()` leaves `RefreshDatabase`'s transaction, so later writes on that application commit to `commerz_test`.

## Findings

**Medium** — `tests/EndToEnd/Support/EndToEndTestCase.php:43`. `bootInReverseOrder()` builds a second application after `RefreshDatabase` has opened its transaction, and that second application is not in the transaction, so its writes commit.

The current reverse-order test only reads, so this suite does not leave rows behind. The method is on the shared harness that P8.2 and P8.4 will call. `RefreshDatabase` rolls back only the connections it began the transaction on (`vendor/laravel/framework/src/Illuminate/Foundation/Testing/RefreshDatabase.php:151`).

REPRODUCTION (PHP 8.4.26, `commerz_test` on `127.0.0.1:54329`):

```text
DB=commerz_test
TX_BEFORE_REVERSE=yes
TX_AFTER_REVERSE=no
STORES=0
```

A test that writes after the reverse boot fails on the transaction check, and the store row stays after rollback:

```php
it('keeps the reverse boot inside the test transaction', function (): void {
    $this->bootInReverseOrder();

    Commerz::stores()->create(new CreateStore('leak', 'Leak', Currency::of('RUB')));

    expect(DB::connection()->getPdo()->inTransaction())->toBeTrue();
});
```

**Low** — `tests/EndToEnd/AssemblyTest.php:32`. The reverse-order test checks that the seven providers are loaded, not that they loaded in reverse, so removing `array_reverse` from `commerzProviders()` still satisfies every assertion in that test once `PluginCatalog` is bound with `singletonIf`.

REPRODUCTION: the assertion is `->toContain(...EndToEndTestCase::allProviders())`. A separate probe of the same method shows the implementation does reverse today (`ORDER_FLIPPED=yes`, sales first and core last, ports and the seven plugins still match). The test itself would stay green for the forward order.

## Checked, no defect

The reverse boot is a new Testbench application. With the providers flipped, the eight ports resolve to the inventory, pricing, promotions, shipping, and `SellableRouter` classes, `sellableTypes()` is `['variant']`, and the stage keys are `base`, `promotions.catalog`, `promotions.cart`, `delivery`, `promotions.delivery`, `tax`, `totals`. `PortMatrix::stages()` sorts by the public `QuoteStage` anchor, position, and key, the same comparison `StagedQuotePipeline` uses. The tests never import an `Internal` class. EndToEnd: 6 passed. `PluginsTest`: 29 passed.

Reverting `PluginCatalog` to `singleton()` makes the core regression red. `register()` again calls `bind()`, which drops the catalog instance that already holds the earlier plugin, so `keys('greeter')` is empty. No other `register()` binding has that shape: port defaults stay `singletonIf`, feature ports stay plain `singleton`, and nothing else binds `PluginCatalog`.

`ShopWorld` matches the shop scenario: channel `web` (RUB, `ru`, prices include tax), S/M/mug, stock 5/2/10 on `msk`, retail 1000/1000/500, VAT 20%, automatic −10%, coupon `SALE100` (−100, 1 per customer, 5 total), courier 300 free above 5000. `taxIncluded: true` on that rate is consistent with in-price delivery. A quote of 2×S + mug is subtotal 2500.00, discount 250.00, delivery 300.00, included VAT 425.00, total 2550.00. The object is readonly and exposes only ids and refs.

Manifests use `^0.1`, no `version` field, and keep `branch-alias` `dev-main` → `0.1.x-dev`. The root still requires `^0.1@dev` and sets `options.versions` for all nine path packages. The lock moves those nine from `dev-main` to `0.1.x-dev`; a published `^0.1` constraint does not need `@dev` once `0.1.0` exists. `composer validate` was not run.

`EndToEndTestCase::$reversed` is cleared in `tearDown()` before the application is destroyed. The product diff outside the harness is the logged `PluginCatalog` fix and its regression test. `plans/` was ignored.

`scripts/php84` could not refresh its shim (`build/php84/bin` is not writable here). Both suites ran with the existing `build/php84/bin/php` → PHP 8.4.26.

Verdict: fail

The assembly does prove the port matrix, plugin survival, and stage order for a genuinely reversed provider list, and the catalog fix is the one order-sensitive binding. The residual risk is that the cart total of 2550.00 also holds if the tax stage or the empty catalog and delivery promotion stages are absent, because included VAT does not change the total and those stages add nothing to this basket. Membership of those stages is asserted separately and was present on the quote (included VAT 425.00, one `promotions.cart` adjustment of −250.00), but the quote test does not lock them.