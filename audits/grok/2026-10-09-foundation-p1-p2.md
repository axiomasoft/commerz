# Additional foundation review — Grok 4.7

Native session: `01a11f3a-9bad-71d3-af8b-3640b7edbc7e`; requested route `grok-4.7/xhigh`.

This is the reviewer’s response before the primary executor corrected the three remaining findings. Its coverage and limitations are preserved below. Final triage and execution evidence live in PLAN1 `knowledge/audit-p1-p2-2026-10-09.json`.

## Verdict

No additional high-severity defect turned up in the code this review actually read. The six regressions you listed are treated as closed and are not re-opened. Two earlier suspicions (advisory-lock int4 binding, and `whereExists` dropping bindings) are withdrawn. What remains are three low-severity contract gaps, plus items this pass did not inspect.

## Closed or withdrawn

Treated as repaired, not re-audited here: ambient savepoint atomicity, relay-inside-host-transaction, numeric projection overflow, prefix-only text equality, optional dependencies granting kind ownership, and numeric PHP keys in variant generation and sellable attributes. This pass did see the savepoint join in `DatabaseTransactions::run`, the relay guard in `OutboxRelay::relay`, and the full-value text predicate in `FilterCompiler::text`. It did not re-read the numeric-column migration or the numeric-key fixes after those repairs. Follow-on regressions from the numeric-key fixes were not inspected.

Withdrawn:

- **Advisory lock key `2849870896`.** Concurrent category-move tests already pass, so there is no demonstrated int4 binding failure. `Locks::categoryTree` (`packages/catalog/src/Common/Internal/Locks.php:13-18`) stays unchallenged.
- **`whereExists` losing filter bindings.** In the installed Laravel, `Builder::addWhereExistsQuery` copies subquery bindings (`vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:2262-2268`). Equals and `in` search tests already pass.
- **Category move versus archive cycle.** `move` holds the tree advisory lock and then `FOR SHARE` on the new parent; `archive` and `create` take `FOR NO KEY UPDATE` / `FOR SHARE` on that parent (`DatabaseCategories.php:108-133`, `136-160`, `53-60`). That blocks the interleavings that would archive a parent and still attach a child. Not reported as a defect.
- **Listing window versus purchasability.** Both use an exclusive `available_until` (`ProductQuery.php:111-112`, `VariantSellables.php:294-299`).
- **Reference terms truncated at 255.** A `Ref` is at most 64 + 1 + 120 bytes (`packages/core/src/Identity/Ref.php:24-26`), under `varchar(255)`.

## Remaining defects

**1. Category search is not one snapshot. Low. P2.9.**

`ProductQuery::rows` loads the category subtree in its own statement (`packages/catalog/src/Products/Internal/ProductQuery.php:64-75`, `120-128`), then applies those ids in a second statement. Catalog-design section 7 puts the recursive CTE inside the product `EXISTS`, which is one PostgreSQL snapshot. A commit of `move` between the two statements includes or omits a product the single-statement query would not. Reads only; no write corruption.

Repair: keep the recursive CTE in the product statement, as the design SQL does.

**2. Sellable option map collapses axes that share a resolved label. Low. P2.10.**

`VariantSellables::options` keys the snapshot by the localized option name (`packages/catalog/src/Variants/Internal/VariantSellables.php:173-176`). Two different option codes whose `resolve($locale)` strings match overwrite one entry. Axis identity in storage is the option id; the quote snapshot then drops an axis.

Repair: key by option code (labels can stay in the value, or use a list of pairs).

**3. Category pages allow `first` up to 500. Low. P2.8.**

`ListCategories` rejects only `first > 500` (`packages/catalog/src/Categories/Data/ListCategories.php:26-28`). `ListProducts` and the catalog-design paging rule use 1..200 (`ListProducts.php:43-45`). Callers can ask for a page the rest of the API refuses.

Repair: use the same 1..200 check and `common.invalid_page_size`.

## Coverage

Read in full or near-full: `AttributeWriter`, `FilterCompiler`, `ProductQuery`, all ten attribute types, `IndexFact`, `DatabaseAttributes`, `DatabaseVariants` (create, generate, update, attributes, archive, restore, list), `OptionSignature`, `GenerationPlan`, `SkuPattern`, `AxisResolver`, `DatabaseCategories`, `Locks`, `DatabaseListings`, `VariantSellables`, `DatabaseProducts` through `list`, `DatabaseProductTypes` create/update/archive, `ListProducts`, `AttributeFilter`, `GenerateVariants`, `PublishProduct`, `MoveCategory`, `CreateCategory`, the attribute-index migration, `DatabaseTransactions`, `Transactions` contract, `DatabaseOperationReceipts`, `ConnectionCommittedEvents`, `Fingerprint`, `OutboxRelay::relay`, `DatabaseChannels::assertActive`, and `docs/architecture/invariants.md` catalog lock section. Laravel `ManagesTransactions`, `DatabaseTransactionsManager`, and query-builder `whereExists` were read to check the savepoint and binding assumptions.

Plan items those files implement: **P1.4** (transactions, receipts, committed events, outbox relay only), **P2.3** (attribute types), **P2.4** (attribute definitions), **P2.6** (writer and projection), **P2.7** (variants), **P2.8** (categories, listings), **P2.9** (search), **P2.10** (sellable provider). **P1.1–P1.3, P1.5–P1.8, P2.1, P2.2, P2.5** were not reviewed as wholes.

## Limits

No tests and no SQL were run. Feature tests were sampled only where a line was already open (`AttributeWriterTest` projection expectation, `ProductSearchTest` long-text case, `ScalarTypesTest` term length). Not reviewed: `Documents`, options module, product-kind classes, `CatalogPlugin` and the plugin compiler, quote pipeline, money and measures beyond `Unit` as used by `MeasureType`, schema migrations other than `attribute_index`, arch-gate implementation, testkit concurrency, and the post-fix numeric-key code. A boolean `false` projection through PDO was not proven. Duplicate ids in `AssignProductCategories` were not checked because that command class was not read.
