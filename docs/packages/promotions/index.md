# axiomasoft/commerz-promotions

Discounts, coupons and usage limits. It contributes the three promotion quote
stages and implements the core's `PromotionUsage` port, which spends limits
only when an order is placed.

## Install

```bash
composer require axiomasoft/commerz-promotions
```

Requires the core. Provider `Commerz\Promotions\PromotionsServiceProvider`.

## Configuration

`--tag=commerz-promotions-config` → `config/commerz-promotions.php`.

| Key | Default | Meaning |
|---|---|---|
| `rounding` | `half_up` | How a percentage effect rounds to the minor unit |
| `max_codes` | `5` | Codes read from one quote; the rest are `too_many_codes` |
| `restore_on` | `['cancelled', 'expired']` | Reasons for which usage is given back; add `refunded` to restore on a full refund |
| `coupons.alphabet` | `23456789ABCDEFGHJKMNPQRSTUVWXYZ` | Characters a `#` in a generation pattern takes |
| `coupons.max_generate` | `50000` | Most coupons one `GenerateCoupons` command creates |

## Root and facade

`Commerz\Promotions\Promotions` (facade `Commerz\Promotions\Facades\Promotions`):

| Accessor | Methods |
|---|---|
| `promotions()` | `create`, `update`, `pause`, `resume`, `archive`, `assignChannels`, `unassignChannels`, `find`, `findByCode`, `list`, `explain` |
| `coupons()` | `create`, `generate`, `disable`, `enable`, `setLimit`, `find`, `findByCode`, `list` |

A promotion has a stage (`catalog`, `cart` or `delivery`), an activator
(`automatic` or `coupon`), conditions and effects, stacking (`Stackable` or
`Exclusive`), `stop_further`, a priority, channels, a period, and optional
limits: total uses, uses per customer, a budget. Coupon codes are canonical
upper-case; `PromotionCodes::couponCode` is the one normalization.

Within a stage promotions run by `priority DESC, code ASC`; stackable ones apply
one after another to the reduced price. A promotion applies whole or not at all
and never takes a price below zero.

## Kinds

| Kind | Built-in keys |
|---|---|
| `promotion_condition` | `cart.subtotal_at_least`, `cart.quantity_at_least`, `line.sellable_in`, `line.category_in`, `customer.in_group` |
| `promotion_effect` | `line.percent_off`, `line.fixed_off`, `order.percent_off`, `order.fixed_off`, `delivery.percent_off`, `buy_x_get_y` |
| `promotion_activator` | `automatic`, `coupon` |

Plugin `commerz.promotions`; quote stages `promotions.catalog`,
`promotions.cart`, `promotions.delivery`. A rule is stored by key and
`config_version`; a missing plugin makes the promotion `unsupported` (not
applied), listed by `commerz:promotions:reconcile`.

## Tables

Prefix + `promotions`, `promotion_channels`, `promotion_rules`,
`promotion_customer_usages`, `coupon_batches`, `coupons`,
`promotion_redemptions`, `promotion_usages`.

## Commands

| Command | |
|---|---|
| `commerz:promotions:reconcile` | compare counters with the usage log, list unsupported rules; exit 1 on any discrepancy |

## Events

Domain events `PromotionChanged`, `CouponsGenerated`. See the
[events reference](../../reference/events).

## Guarantees

Limits and budgets are never exceeded concurrently; a limit is spent at
placement, never when a code is entered; `consume` and `restore` are idempotent
per order. Counters exist only while a limit is set.

API reference: [promotions](../../reference/api/promotions).
