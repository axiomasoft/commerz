# axiomasoft/commerz-shipping

Delivery zones, methods and tariffs, delivery options for a cart, and carrier
bookings. It implements the core's `DeliveryOptions` and `ShipmentBooking`
ports and contributes the `delivery` quote stage.

## Install

```bash
composer require axiomasoft/commerz-shipping
```

Requires the core. Provider `Commerz\Shipping\ShippingServiceProvider`.

## Configuration

`--tag=commerz-shipping-config` → `config/commerz-shipping.php`.

| Key | Default | Meaning |
|---|---|---|
| `tax_included` | `false` | Whether a rate's price includes tax when `ZoneRate::$taxIncluded` is null |
| `max_zone_members` | `2000` | Most members one zone command may carry (1..100000) |
| `booking.lease_seconds` | `120` | Seconds a fresh `pending` booking counts as in progress (10..3600); must exceed the carrier's timeout |

## Root and facade

`Commerz\Shipping\Shipping` (facade `Commerz\Shipping\Facades\Shipping`):

| Accessor | Methods |
|---|---|
| `zones()` | `create`, `update`, `setMembers`, `archive`, `restore`, `find`, `list` |
| `methods()` | `create`, `update`, `setRates`, `assignChannels`, `unassignChannels`, `enable`, `disable`, `archive`, `find`, `findByCode`, `list` |
| `options()` | `list`, `explain` — the methods that can deliver for a request, with prices |
| `bookings()` | `find`, `list` — the carrier-call journal |

A **zone** is a set of members — a country, a region of it, or a postal prefix.
A **method** has a code, a carrier, channels and, per (zone, currency), a
**rate**: a rule key with parameters, an optional `free_above` threshold, a tax
flag and an ETA. Zone choice is deterministic (specificity, then code). The
price shown in options, in `explain` and in the quote is computed by one path.

## Kinds

| Kind | Contract | Built-in keys |
|---|---|---|
| `delivery_rate` | `DeliveryRate` (pure `price()` → `RatePrice`) | `flat`, `weight_table` |
| `carrier` | `Carrier` (`key`, `book`, `find`) | `manual` |

Plugin `commerz.shipping`; quote stage `delivery`. A tariff with an unknown rule
or carrier is fail-closed: not offered, but visible to the admin.

## Tables

Prefix + `shipping_zones`, `shipping_zone_members`, `shipping_methods`,
`shipping_method_channels`, `shipping_rates`, `shipping_bookings`.

## Commands

None.

## Events

Domain events `ZoneChanged`, `MethodChanged`, `ShipmentBooked`. See the
[events reference](../../reference/events).

## Booking

`ShipmentBooking` books through the method's carrier outside any transaction
(`shipping.booking_inside_transaction` otherwise), keyed by a `reference` in the
journal: one carrier call at a time per reference, a stored result for the same
payload, `IdempotencyConflict` for another. After an unknown outcome the carrier's
`find` runs before any new booking. See [carrier](../../how-to/carrier).

API reference: [shipping](../../reference/api/shipping).
