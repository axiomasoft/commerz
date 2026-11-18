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
| Tenant | data isolation boundary | **no column**: a separate connection or database chosen by the host (D6, legacy-platform ADR-004) |
| Store | business unit: seller, default currency, order numbering | `cmz_stores` (core) |
| Channel | point of sale: website, POS, marketplace, B2B, CRM manager | `cmz_channels` (core): store, currencies, locales, `prices_include_tax`, status |
| Stock location | warehouse, shop, pickup point | `cmz_stock_locations` (inventory); channel ↔ locations with priority in `cmz_stock_sources` |
| Stock item and level | what lies on the shelf; its quantity per location | `cmz_stock_items` (policy `tracked \| backorder \| untracked`), `cmz_stock_item_links` (sellable → item × `quantity_per_unit`, several rows make a kit), `cmz_stock_levels` (`on_hand`, `reserved`, `safety_stock`, `version`), append-only `cmz_stock_movements` |
| Reservation and transfer | held stock; goods moving between locations | `cmz_stock_reservations` (header per demand) + `_reservation_lines`; `cmz_stock_transfers` + `_transfer_lines` |
| Price list and price | what a sellable costs in a channel; windows never overlap (PRC-01) | `cmz_price_lists` (code, currency, `priority`, `tax_included` null = as channel, status), `cmz_price_list_channels`, `cmz_prices` (`sellable_ref`, `customer_group`, `min_quantity`, `amount_minor`, `compare_at_minor`, `valid_from`/`valid_until`) |
| Tax class, zone and rate | what tax applies where and when (TAX-01) | `cmz_tax_classes`, `cmz_tax_zones`, `cmz_tax_zone_members` (`country`, `region`; unique nulls not distinct), `cmz_tax_rates` (`rate_bp`, `code`, validity window) |
| Shipping zone, method, tariff and booking | where, with what and for how much a parcel goes; one carrier call per reference (SHP-03) | see *Shipping tables* below |
| Promotion, rule, coupon and usage | what is discounted and how often it may be spent (PRM-01, PRM-03) | see *Promotions tables* below |
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

## Promotions tables

Owned by `commerz-promotions`, nouns `promotion` and `coupon`, three migrations
(`create_commerz_promotion_tables`, `…_coupon_tables`, `…_promotion_usage_tables`).
Inside the package ordinary foreign keys connect its own tables; there is none to
a core or another package's table: the channel is `channel_id uuid` checked
through `Channels::assertActive`, the customer and the order are `*_ref
varchar(191)` strings.

| Table | Holds | Notes |
|---|---|---|
| `cmz_promotions` | the promotion: `code` (unique), `stage`, `activator`, `stacking`, `stop_further`, `priority`, `currency`, `budget_minor`, `status`, `starts_at`/`ends_at`, `usage_limit`, `per_customer_limit`, the counters `used` and `spent_minor`, `revision` | enums as `varchar` + `CHECK`; `CHECK` window `starts_at < ends_at`, budget > 0 and only with a currency |
| `cmz_promotion_channels` | the channels a promotion is assigned to (`PK (promotion_id, channel_id)`, index on `channel_id`) | no foreign key to `cmz_channels` |
| `cmz_promotion_rules` | conditions and effects: `role`, `position`, `rule_key`, `config_version`, `config jsonb` | JSONB with an owning codec only; a rule is addressed by its stable key, never a class; rules are replaced whole on update |
| `cmz_promotion_customer_usages` | `(promotion_id, customer_ref) → used` | rows exist only while `per_customer_limit` is set |
| `cmz_coupon_batches` | one `GenerateCoupons` run: pattern, count, default limit | |
| `cmz_coupons` | `code` (unique), `status`, `usage_limit`, `used`, `promotion_id`, `batch_id` | `CHECK code ~ '^[A-Z0-9][A-Z0-9_-]{0,63}$'` |
| `cmz_promotion_redemptions` | one header per placed order: `order_ref` (unique), `customer_ref`, `fingerprint`, `state` (`consumed`, `restored`), `restore_reason` | the idempotency key of `consume` and `restore` |
| `cmz_promotion_usages` | one row per promotion of an order: `redemption_id`, `promotion_id`, optional `coupon_id`, `discount_minor`, `currency` | append-only log the counters are recounted from; the foreign key to `promotions` is part of the lock protocol |

- **Coupon code canon.** A coupon code is stored upper-case and trimmed; the
  `CHECK` is the last line of defence, `PromotionCodes::couponCode` the only
  normalization of writing and searching (PRM-03). A promotion `code` is
  lower-case `[a-z0-9][a-z0-9_.-]{0,63}`.
- **Counters only with limits (D27).** `used` is kept only while `usage_limit`
  is set, `spent_minor` only while `budget_minor` is set, `cmz_coupons.used` only
  while the coupon has a limit, customer rows only while `per_customer_limit` is
  set. A promotion without limits is never written to by a placement. Setting a
  limit recounts the counter from the consumed usages under `FOR UPDATE`;
  removing it zeroes the counter.
- **History.** Promotions and coupons are paused, disabled or archived, never
  deleted; usages and redemptions are never deleted, `restore` only flips the
  header to `restored`.
- Percent effects round with `commerz-promotions.rounding` (default `half_up`);
  amounts are `Money`, rates basis points (`rateBp`).

## Shipping tables

Owned by `commerz-shipping`, noun `shipping`. Foreign keys only inside the
package; `channel_id` is a uuid checked through `Channels::assertActive`.

| Table | Holds | Notes |
|---|---|---|
| `cmz_shipping_zones` | `code` (unique), `name`, `status` (`active`, `archived`) | zones are archived, never deleted |
| `cmz_shipping_zone_members` | `country`, `region`, `postal_prefix` | `UNIQUE … NULLS NOT DISTINCT`; a member is a country, a region of it, or a postal prefix of it |
| `cmz_shipping_methods` | `code` (unique), `name` jsonb, `carrier`, `status` (`active`, `disabled`, `archived`), `position` | |
| `cmz_shipping_method_channels` | `PK (method_id, channel_id)` | no foreign key to `cmz_channels` |
| `cmz_shipping_rates` | per (method, zone, currency): `rate_key`, `rate_version`, `params` jsonb, `free_above_minor`, `tax_included`, `eta_days` | `UNIQUE (method, zone, currency)`; `params` is an object written by the rule's codec |
| `cmz_shipping_bookings` | the carrier-call journal: `reference` (unique), `carrier`, `method_code`, `fingerprint`, `state`, `attempts`, `claimed_at`, `tracking`, `label_ref`, `result`, `error_*`, `booked_at` | `CHECK` that `booked` has tracking and `booked_at` |

Lock order: *Shipping lock order* in `invariants.md`.

## Sales tables

Owned by `commerz-sales`. Foreign keys only inside the package; `channel_id` and
`store_id` are uuids without a foreign key (checked through `Channels::assertActive`),
references to other packages are `*_ref` strings (`sellable_ref`, `customer_ref`).
Only sales writes to these tables (single writers: `CartRows`, `OrderWriter`,
`OrderLedger`, `PaymentEventInbox`, `PaymentJournal`, `RefundJournal`).

| Table | Holds | Notes |
|---|---|---|
| `cmz_carts`, `cmz_cart_lines` | cart of kind `cart` or `draft`, state `open \| converted`, `version`, channel, currency, locale, customer, codes, addresses, delivery method | `UNIQUE (cart_id, sellable_ref)`; channel and currency are never updated (CTX-01) |
| `cmz_orders` | the snapshot header: number, `origin`, `state`, `payment_state`, `fulfillment_state`, cancel reason, totals, `amount_due`, `authorized`, `captured`, `refunded`, `quote_fingerprint`, `payment_due_at` | `UNIQUE (cart_id)` (ORD-01), `UNIQUE (store_id, number)`; `CHECK`s tie `cancelled_at` and `completed_at` to the state and keep `refunded ≤ captured` |
| `cmz_order_lines` | the snapshot of a quoted line (sellable, sku, name, kind, unit price, adjustments, taxes, totals) and the ledger `cancelled`, `fulfilled`, `returned` quantities | `id` = the cart line id; `CHECK cancelled + fulfilled ≤ quantity`, `returned ≤ fulfilled` (ORD-03) |
| `cmz_order_events` | append-only history of an order | read through `Orders::history` |
| `cmz_payments` | a payment attempt: `reference` (unique), `gateway`, `status`, amounts, `fingerprint`, `attempts`, `claimed_at` (lease), `external_id` | `UNIQUE (gateway, external_id)`; `CHECK` captured/authorized within amount, `refunded ≤ captured` |
| `cmz_payment_events` | the provider-event inbox: `external_event_id`, `fingerprint`, `outcome` (`applied \| ignored`), target payment or refund | `UNIQUE (gateway, external_event_id)` (PAY-01); exactly one target |
| `cmz_refunds` | a refund attempt of a payment, same journal fields as payments, optional return | `UNIQUE (reference)`, `UNIQUE (gateway, external_id)` |
| `cmz_fulfillments`, `cmz_fulfillment_lines` | shipped units per location, status `created \| shipped \| delivered`, carrier, tracking, `booking_requested` (durable intent, default false) | booking intent is added by a separate migration; existing rows retain their shipment data |
| `cmz_returns`, `cmz_return_lines` | a return: requested, received (with the `restocked` count per line), cancelled | |
| `cmz_order_number_seq` | the sequence of the built-in numbering (starts at 1001) | gaps are fine; not an invoice number (D34) |

Lock order: *Sales lock order* in `invariants.md`.

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
