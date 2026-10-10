# A carrier

A carrier is a contribution of kind `carrier`. Shipping calls it only from
`ShipmentBooking`, **outside database transactions**.

```php
use Commerz\Shipping\Carriers\Contracts\Carrier;

final class AcmeCarrier implements Carrier
{
    public function key(): string { return 'acme'; }

    public function book(CarrierBooking $booking): BookedShipment { /* … */ }
    public function find(CarrierBooking $booking): ?BookedShipment { /* … */ }
}
```

Register it in a plugin that lists `commerz.shipping` in `requires` (shipping declares
the kind): `$registrar->contribute(Carrier::KIND, AcmeCarrier::class)`, then
create a method that names it (`new CreateMethod('express', $name, 'acme', …)`).

## The rules

1. **Pass the reference.** `$booking->shipment->reference` is unique per
   booking. Send it to the carrier as the sender's number whenever its API
   allows, so `find()` can locate the shipment later.
2. **`CarrierRejected` is a final refusal** — the carrier certainly created
   nothing. The booking is rejected.
3. **Any other exception is an unknown outcome** (a timeout, a broken
   connection). The journal turns `unknown`; before any new booking, `find()` runs.
4. **`find` returns `null` only when the carrier confirms there is no such
   shipment**, so booking a new one is safe. Throw when you cannot tell.
5. `book` returns `BookedShipment(carrier, tracking, labelRef, meta)`.

## What Commerz guarantees around you

- **One carrier call at a time per reference.** The journal
  (`cmz_shipping_bookings`) is unique by `reference` and fenced by `attempts`
  and a lease (`commerz-shipping.booking.lease_seconds`, keep it above your
  request timeout).
- **The same payload returns the stored result** without calling you again; a
  different payload is `IdempotencyConflict`.
- **A booked replay needs no carrier**: archiving a method or removing your plugin
  later never loses a stored result.
- **Never inside a transaction**: booking from a host transaction fails with
  `shipping.booking_inside_transaction`.

## Prices are not the carrier's job

Delivery prices come from tariffs — `flat`, `weight_table` or a custom
`delivery_rate` contribution — which are pure and need no network. A carrier
only books. If your carrier quotes live prices, compute them in your host and
store them as the method's rates.

## The manual carrier

`manual` has no network; the tracking number comes from the booking's
`meta['tracking']`, otherwise the reference. The [quick start](../getting-started/quick-start-shop)
uses it.
