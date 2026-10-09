# Data conventions

Rules every Commerz migration, model and query follows. They exist so that
invariants can live in PostgreSQL, packages can share one connection without
colliding, and stored history never depends on live data.

## Tables and constraints

- Prefix `cmz_` + the package's noun: `cmz_stock_levels`,
  `cmz_promotion_usages`. The prefix is configurable before the first install
  only; its fingerprint is stored in `cmz_schema_state`, and changing it later
  is a boot error. Third-party plugin tables: `cmz_x_<vendor>_*`.
- One resolver, `TableNames`, serves migrations, models, raw SQL and
  diagnostics. No per-table name map. Migrations extend `CommerzMigration`.
- Constraint names: `{table}_{cols}_{uq|ix|fk|ck}`, primary key `{table}_pk`.
  Names longer than 63 bytes are shortened deterministically with a hash
  suffix (`ConstraintNames`).
- `EXCLUDE` constraints are named `{table}_{name}_ex` (`ConstraintNames::exclusion`,
  `CommerzMigration::exclude`): `cmz_prices_window_ex`, `cmz_tax_rates_window_ex`.
- PostgreSQL ≥ 16 only (D2). Invariants may rely on `EXCLUDE` constraints,
  partial unique indexes, `jsonb` and transactional DDL. Scalar equality inside
  `EXCLUDE USING gist` needs the `btree_gist` extension; the pricing migration
  runs `create extension if not exists btree_gist` (trusted since PostgreSQL 13:
  the role needs `CREATE` on the database, not superuser) and `down()` keeps it.

## Keys and references

- Entity primary keys are `uuid`, UUIDv7 generated in the application before
  `INSERT` (D7). In PHP they are typed `final readonly` ID classes
  (`ProductId`, `OrderId`) extending `EntityId`; `Uuid7` is the generator and
  test seam.
- Append-only journals (`cmz_stock_movements`, `cmz_order_events`,
  `cmz_outbox_messages`) use `bigint generated always as identity`.
- References to the host or to another package are `*_ref varchar(191)` in the
  form `type:id`, without foreign keys (`sellable_ref`, `customer_ref`,
  `order_ref`). In PHP: `SellableRef`, `CustomerRef`, `ActorRef`.

## Money, rates, quantities

- Money columns: `*_minor bigint` + `currency char(3)`. In PHP:
  `Money(int $amountMinor, Currency $currency)` (D8).
- Intermediate arithmetic (percentages, taxes, allocation) uses `brick/math`
  with an explicit `RoundingMode`. Splitting an amount over lines uses the
  largest remainder method; the parts always sum to the whole
  (`MoneyAllocator`).
- Rates are basis points: `rate_bp int` (`2000` = 20 %).
- Quantities are `int` in the base unit of the stock item (g, ml, mm, pcs).
  The unit code lives on the stock item; the sellable → stock item factor
  lives on the link and is frozen in the operation snapshot. Weighted and
  metered goods sell in grams or millimetres with a sale step.
- Floats never represent money or quantities, in storage or in signatures.

## Types, time, lifecycle

- Enums: `varchar` + `CHECK` in the database, backed enums in PHP.
- Time: `timestamptz` in UTC; business dates are `date`.
- No soft deletes: explicit statuses (`active|archived`); historical documents
  are never deleted.
- JSONB only for documents with an owning codec: attribute values, rule
  configuration with `config_version`, outbox payload. Never for fields that
  take part in invariants.

## Snapshots

An order line stores its name, SKU, options, unit, price, tax, allocated
discounts and stock recipe independently of the live catalog. Recomputing live
prices never changes a placed order (ORD-02).

## Tenancy and operation context

| Concept | Meaning | Where |
|---|---|---|
| Tenant | data isolation boundary | **no column**: a separate connection or database chosen by the host (D6, CoreX ADR-004) |
| Store | business unit: seller, default currency, order numbering | `cmz_stores` (core) |
| Channel | point of sale: website, POS, marketplace, B2B, CRM manager | `cmz_channels` (core): store, currencies, locales, `prices_include_tax`, status |
| Stock location | warehouse, shop, pickup point | `cmz_stock_locations` (inventory); channel ↔ locations with priority in `cmz_stock_sources` |
| Stock item and level | what lies on the shelf; its quantity per location | `cmz_stock_items` (policy `tracked \| backorder \| untracked`), `cmz_stock_item_links` (sellable → item × `quantity_per_unit`, several rows make a kit), `cmz_stock_levels` (`on_hand`, `reserved`, `safety_stock`, `version`), append-only `cmz_stock_movements` |
| Reservation and transfer | held stock; goods moving between locations | `cmz_stock_reservations` (header per demand) + `_reservation_lines`; `cmz_stock_transfers` + `_transfer_lines` |
| Price list and price | what a sellable costs in a channel; windows never overlap (PRC-01) | `cmz_price_lists` (code, currency, `priority`, `tax_included` null = as channel, status), `cmz_price_list_channels`, `cmz_prices` (`sellable_ref`, `customer_group`, `min_quantity`, `amount_minor`, `compare_at_minor`, `valid_from`/`valid_until`) |
| Tax class, zone and rate | what tax applies where and when (TAX-01) | `cmz_tax_classes`, `cmz_tax_zones`, `cmz_tax_zone_members` (`country`, `region`; unique nulls not distinct), `cmz_tax_rates` (`rate_bp`, `code`, validity window) |
| Customer | the host's buyer | `CustomerRef` + `CustomerDirectory` port; groups drive prices and promotions |
| Actor | who executes a command (operator, customer, system) | `ActorRef` |

Several stores and channels in one database are supported natively; a shared
schema for many tenants is not.

`CommerceContext` is `final readonly`: `ChannelId $channel`,
`Currency $currency`, `string $locale`, `?CustomerRef $customer`,
`list<string> $customerGroups`, `DateTimeImmutable $at`. It is passed
**explicitly** into every pricing and sales operation, never held in a
singleton or global scope. A host's `ContextResolver` only helps build it from
a request. A cart fixes its channel and currency at creation; they never
change (CTX-01).

## Connection and host

All packages use one connection, `commerz.connection` (`null` = the default
connection). The host supplies the connection, `CustomerDirectory` (otherwise
guests only), its own `SellableProvider`s, authorization on its transport
(the core checks invariants and context, not permissions), a queue for the
outbox relay and payment/delivery adapters.

## Catalog projection precision and text equality

`attribute_index.num` is unconstrained PostgreSQL `numeric` after migration
`2026_10_09_100009`; built-in decimal and measure values keep up to 30 whole
digits and 12 fractional digits, and contributed types keep their exact numeric
facts. A narrowing rollback refuses to round values or overflow them.

Text projection terms retain a 255-character prefix for the bounded index.
`equals` and `in` also compare the complete canonical JSONB document value of
the matching product or variant, so distinct texts sharing that prefix do not
match one another. Attribute codes and values are SQL bindings.

Migration `2026_10_09_100010` widens variant measurements and the explicit
variant/category positions to `bigint`, matching the non-negative PHP int64
values their commands accept. Automatic variant positions refuse an int64
overflow before insertion. The category subtree and product assignments are
read in the same SQL statement and PostgreSQL snapshot.
