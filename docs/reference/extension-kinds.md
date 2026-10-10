# Extension kinds

The closed set of contribution kinds. A kind is declared by the package that owns
its SPI; a plugin contributes under a stable key with
`PluginRegistrar::contribute($kind, $contribution)`. How-to:
[write a plugin](../how-to/write-a-plugin); model:
[ports and plugins](../concepts/ports-and-plugins) and
[Extension model](../architecture/extension).

| Kind | Declared by plugin | Contract | Built-in keys |
|---|---|---|---|
| `sellable_provider` | `commerz.core` | `Commerz\Ports\Sellables\Contracts\SellableProvider` | `variant` (catalog) |
| `quote_stage` | `commerz.core` | `Commerz\Quotes\Contracts\QuoteStage` | `base`, `totals` (core); `promotions.catalog`, `promotions.cart`, `promotions.delivery` (promotions); `delivery` (shipping); `tax` (pricing) |
| `outbox_consumer` | `commerz.core` | `Commerz\Outbox\Contracts\OutboxConsumer` | — |
| `attribute_type` | `commerz.catalog` | `Commerz\Catalog\Attributes\Contracts\AttributeType` | `text`, `localized_text`, `integer`, `decimal`, `boolean`, `date`, `enum`, `multi_enum`, `measure`, `reference` |
| `product_kind` | `commerz.catalog` | `Commerz\Catalog\ProductTypes\Contracts\ProductKind` | `physical`, `digital`, `service` |
| `allocation_strategy` | `commerz.inventory` | `Commerz\Inventory\Allocation\Contracts\AllocationStrategy` | `priority`, `single_location` |
| `tax_calculator` | `commerz.pricing` | `Commerz\Pricing\Calculation\Contracts\TaxCalculator` | `rates` |
| `promotion_condition` | `commerz.promotions` | `Commerz\Promotions\Rules\Contracts\PromotionCondition` | `cart.subtotal_at_least`, `cart.quantity_at_least`, `line.sellable_in`, `line.category_in`, `customer.in_group` |
| `promotion_effect` | `commerz.promotions` | `Commerz\Promotions\Rules\Contracts\PromotionEffect` | `line.percent_off`, `line.fixed_off`, `order.percent_off`, `order.fixed_off`, `delivery.percent_off`, `buy_x_get_y` |
| `promotion_activator` | `commerz.promotions` | `Commerz\Promotions\Rules\Contracts\PromotionActivator` | `automatic`, `coupon` |
| `delivery_rate` | `commerz.shipping` | `Commerz\Shipping\Rates\Contracts\DeliveryRate` | `flat`, `weight_table` |
| `carrier` | `commerz.shipping` | `Commerz\Shipping\Carriers\Contracts\Carrier` | `manual` |
| `payment_gateway` | `commerz.sales` | `Commerz\Sales\Gateways\Contracts\PaymentGateway` | `manual` |
| `order_number_generator` | `commerz.sales` | `Commerz\Sales\Numbering\Contracts\OrderNumberGenerator` | `sequence` |

A package that contributes to a kind lists its declaring plugin in `requires`
(`commerz.promotions` for a promotion rule, and so on). The constant `KIND` on
each contract holds the kind name.

## Contracts at a glance

| Kind | Methods | Purity |
|---|---|---|
| `sellable_provider` | `key()`, `resolve(refs, context)` | reads only |
| `quote_stage` | `key()`, `anchor()`, `position()`, `apply(state)` | pure; no writes, no network, time from the context |
| `outbox_consumer` | `key()`, `consume(event)` | at least once; deduplicate by `eventId`; runs outside the relay's transaction |
| `attribute_type` | `key()`, `configVersion()`, `usesChoices()`, `config()`, `normalize()`, `index()`, `operators()` | pure |
| `product_kind` | `key()`, `sellableKind()`, `requiresShipping()` | pure |
| `allocation_strategy` | `key()`, `allocate()`, `capacity()` | pure, no I/O; the plan is validated against locked levels |
| `tax_calculator` | `key()`, `calculate(TaxRequest): TaxResult` | no writes; result validated by `TaxResultValidator` |
| `promotion_condition` | `key()`, `version()`, `stages()`, `role()`, `normalize()`, `upgrade()`, `evaluate()` | pure; a line filter or a qualifier |
| `promotion_effect` | `key()`, `version()`, `stages()`, `normalize()`, `upgrade()`, `apply(EffectInput): list<Discount>` | pure; result guarded by `DiscountGuard` |
| `promotion_activator` | `key()`, `activate(ActivationRequest): ActivationResult` | may read, never writes |
| `delivery_rate` | `key()`, `version()`, `normalize()`, `upgrade()`, `price()` | pure; no network, time from the context |
| `carrier` | `key()`, `book()`, `find()` | called outside transactions; may use the network |
| `payment_gateway` | `key()`, `initiate()`, `find()`, `refund()`, `findRefund()` | called outside transactions; `reference` is the provider idempotency key |
| `order_number_generator` | `key()`, `next(OrderNumbering)` | runs inside the placement transaction: no network |

## Quote anchors

Fixed, in order: `base`, `promotions.catalog`, `promotions.cart`, `delivery`,
`promotions.delivery`, `tax`, `totals`. A stage declares an anchor and a
position (`Before`, `At`, `After`); only the anchor's owner takes `At`. See
[the quote pipeline](../concepts/quotes-pipeline).

## Promotion stages

`PromotionStage::Catalog`, `Cart`, `Delivery`: the stage a promotion runs in. A
condition or effect declares the stages it may be used in and a promotion
naming a rule outside them is refused
(`promotions.rule_not_allowed_in_stage`).

## Rules for contributions

- The key is stable: data stores it. Renaming a key orphans stored rules.
- A rule decodes and upgrades its own stored configuration by `config_version`.
- A missing or unreadable contribution makes the rule `unsupported` and the
  system fails closed.
- Host keys are vendor-prefixed (`acme.…`).
