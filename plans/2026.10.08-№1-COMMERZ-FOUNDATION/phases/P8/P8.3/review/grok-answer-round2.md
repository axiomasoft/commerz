The correction closes both round-1 findings. No new defect.

`Carts::setCustomer` reads the host directory before its transaction and stores that profile's groups, then the command's groups, deduplicated with the directory first. With no customer it stores the command's groups. An unknown customer (`find()` returns null) keeps the command's groups. Quote and place both use that cart snapshot: the CRM draft passes no groups, the audit line prices at `3600.00` from the `b2b` contract, and the placed order keeps `['b2b']` plus the directory email and name. Repeating `setCustomer` with the same merged groups does not bump the version.

`CreateCart` and `Orders::draft` still copy the groups already on the `CommerceContext`. That matches D38, which assigns the directory read to `setCustomer`.

The absence check now uses the singular noun or `Str::plural`, the same pair `SchemaOwnership` uses. Every catalog, inventory, shipping, and promotions table matches, including `categories`. None of the installed core, pricing, or sales tables match. The CRM test's foreign-table list is empty while `cmz_orders` and `cmz_price_lists` are present.

Ran on `commerz_review2_test` with PHP 8.4.26: Carts 29 passed, Placement 16 passed, `CrmScenarioTest` 2 passed, `CrmMixedHostTest` 1 passed. `scripts/php84` could not create its shim (`build/php84/bin`, permission denied), so these runs used `/usr/bin/php8.4` directly.

Verdict: pass

Residual risk: `docs/architecture/api.md:66` still says `withCustomer()` takes groups from `CustomerDirectory`, while that call only stores the groups the caller passes and create/draft persist those. Directory groups reach a cart at `setCustomer`. The snapshot then stays fixed until the next `setCustomer`, so a later directory change does not move the price. Email, name, and tax exemption are still read live. A slow or throwing directory runs before the cart lock, including for a missing or converted cart and for an idempotent repeat. A directory group that fails the shared `[a-z0-9_.-]{1,64}` rule throws `customers.invalid_group` from `CustomerProfile` before any write. The plural check was matched against migration names and the live CRM schema. Catalog was not installed in this run, so `cmz_categories` alone was not observed in a database.
