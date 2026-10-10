# axiomasoft/commerz-pricing

Price lists, prices with validity windows, and taxes. It implements the core's
`PriceResolver` and contributes the `tax` quote stage.

## Install

```bash
composer require axiomasoft/commerz-pricing
```

Requires the core. Provider `Commerz\Pricing\PricingServiceProvider`. The
migration runs `create extension if not exists btree_gist` (the role needs
`CREATE` on the database).

## Configuration

`--tag=commerz-pricing-config` → `config/commerz-pricing.php`.

| Key | Default | Meaning |
|---|---|---|
| `fallback_to_sellable` | `true` | With no applicable price, use the sellable's base price (same currency only); otherwise `prices.price_not_found` |
| `tax.calculator` | `rates` | Key of the `tax_calculator` contribution |
| `tax.default_class` | `standard` | Tax class of a sellable that names none |
| `tax.delivery_class` | `standard` | Tax class of delivery; `null` leaves delivery untaxed |
| `tax.default_zone` | `null` | Zone used when a quote has no delivery address (POS, CRM, digital goods) |
| `tax.rounding` | `half_up` | `half_up`, `half_even`, `half_down`, `up`, `down`, `ceiling`, `floor` |

## Root and facade

`Commerz\Pricing\Pricing` (facade `Commerz\Pricing\Facades\Pricing`):

| Accessor | Methods |
|---|---|
| `priceLists()` | `create`, `update`, `assignChannels`, `unassignChannels`, `archive`, `restore`, `find`, `list` |
| `prices()` | `set`, `setMany`, `reschedule`, `remove`, `list`, `resolveMany`, `explain` |
| `taxes()` | `defineClass`, `defineZone`, `setZoneMembers`, `setRate`, `rescheduleRate`, `removeRate`, `listClasses`, `listZones`, `listRates`, `zoneFor` |

A **price** belongs to a price list and is keyed by sellable, customer group
and minimum quantity, with an amount, an optional compare-at amount and a
validity window. Windows of one key never overlap; the database refuses it
(`pricing.price_window_overlap`). Selection among applicable prices is a total
order — list priority, lowest amount, group, minimum quantity, list code, price
id — shared by `resolveMany` and `explain`, so an explanation always matches
the price.

A **tax rate** is per (class, zone, code) in basis points with a validity
window; a zone's members are countries or regions, each in one zone. The
`tax` stage computes tax per line on the base after all discounts and rounds
per line.

## Kinds

| Kind | Contract | Built-in keys |
|---|---|---|
| `tax_calculator` | `TaxCalculator` (`key`, `calculate(TaxRequest): TaxResult`; no writes) | `rates` |

Plugin `commerz.pricing`; quote stage `tax`. A calculator's result is validated
(`TaxResultValidator`).

## Tables

Prefix + `price_lists`, `price_list_channels`, `prices`, `tax_classes`,
`tax_zones`, `tax_zone_members`, `tax_rates`.

## Commands and events

No commands. Domain events: `PriceListChanged`, `PricesChanged`,
`TaxRatesChanged`. See the [events reference](../../reference/events).

API reference: [pricing](../../reference/api/pricing).
