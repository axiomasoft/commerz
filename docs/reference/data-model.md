# Data model

Conventions, then the tables each package owns. The binding rules are in
[Data conventions](../architecture/data); this page is the map.

## Conventions

| | |
|---|---|
| Prefix | `cmz_` (configurable before the first install); one resolver, `TableNames`, serves migrations, models, raw SQL and diagnostics |
| Keys | `uuid` UUIDv7 generated in the application; append-only journals use `bigint` identity |
| References | to the host or another package: `*_ref varchar(191)` as `type:id`, no foreign key |
| Money | `*_minor bigint` + `currency char(3)` |
| Rates | basis points, `rate_bp int` |
| Quantities | `int` in the base unit of the stock item |
| Enums | `varchar` + `CHECK` |
| Time | `timestamptz` in UTC; business dates `date` |
| Deletion | no soft deletes; explicit statuses; history is never deleted |
| JSONB | only for documents with an owning codec (attribute values, rule configuration with `config_version`, outbox payload) |
| Names | constraints `{table}_{cols}_{uq\|ix\|fk\|ck}`, primary key `{table}_pk`, exclusion `{table}_{name}_ex`; names over 63 bytes are shortened with a hash |
| Foreign keys | only inside a package; never across packages |
| Single writer | a package writes only the tables it owns, and inside it one class writes each aggregate |

Plugin tables use the noun `x_<vendor>` (`cmz_x_acme_…`).

## Tables by package

| Package | Table nouns (prefix omitted) |
|---|---|
| core | `stores`, `channels`, `schema_state`, `operation_receipts`, `outbox_messages` |
| catalog | `products`, `product_types`, `product_type_attributes`, `product_type_options`, `product_options`, `options`, `option_values`, `variants`, `variant_option_values`, `attributes`, `attribute_choices`, `attribute_index`, `categories`, `category_products`, `listings` |
| inventory | `stock_locations`, `stock_items`, `stock_item_links`, `stock_levels`, `stock_movements`, `stock_sources`, `stock_reservations`, `stock_reservation_lines`, `stock_transfers`, `stock_transfer_lines` |
| pricing | `price_lists`, `price_list_channels`, `prices`, `tax_classes`, `tax_zones`, `tax_zone_members`, `tax_rates` |
| promotions | `promotions`, `promotion_channels`, `promotion_rules`, `promotion_customer_usages`, `coupon_batches`, `coupons`, `promotion_redemptions`, `promotion_usages` |
| shipping | `shipping_zones`, `shipping_zone_members`, `shipping_methods`, `shipping_method_channels`, `shipping_rates`, `shipping_bookings` |
| sales | `carts`, `cart_lines`, `orders`, `order_lines`, `order_events`, `payments`, `payment_events`, `refunds`, `fulfillments`, `fulfillment_lines`, `returns`, `return_lines`, sequence `order_number_seq` |

## Key relationships

- A **channel** belongs to a store; prices (`price_list_channels`), promotions,
  shipping methods, stock sources and catalog listings are assigned to channels
  by `channel_id` without a foreign key; the owner checks it through
  `Channels::assertActive`.
- A **variant** is a catalog row; its `SellableRef` is what stock links,
  prices and cart lines reference, as a string.
- **Stock**: item → level per location; every `on_hand` change writes one
  movement; a reservation header per demand (`order:<id>`) owns its lines.
- **Orders** are snapshots: `order_lines` copy name, SKU, prices, taxes and
  adjustments, and keep a ledger of cancelled, fulfilled and returned quantities.
- **Promotions** keep counters only while a limit is set; a redemption header per
  order and a usage log per promotion make `consume` and `restore` idempotent.

## Constraints that carry invariants

| Constraint | Invariant |
|---|---|
| `UNIQUE (cart_id)` on orders | an order is placed once (ORD-01) |
| `UNIQUE (demand)` on reservation headers | a demand holds one set of reservations (INV-05) |
| `cmz_prices_window_ex`, `cmz_tax_rates_window_ex` (`EXCLUDE USING gist`) | validity windows never overlap (PRC-01, TAX-01) |
| `UNIQUE (gateway, external_event_id)` | a provider event applies once (PAY-01) |
| `UNIQUE (reference)` on bookings, payments, refunds | one provider call per reference |
| `CHECK reserved ≥ 0`, `on_hand ≥ 0` on levels | stock never negative (INV-02) |
| `CHECK refunded ≤ captured` | a refund never exceeds the capture (SAL-01) |

The registry with owners and tests is [Invariants](../architecture/invariants).
Read-side access to these tables belongs to the owner's contracts; do not write
to them from outside.
