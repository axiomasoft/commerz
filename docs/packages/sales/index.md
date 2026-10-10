# axiomasoft/commerz-sales

The sales process: carts, checkout, orders, payments, fulfillment, returns and
refunds. It is a consumer of the core ports — stock, prices, promotions,
delivery — so it works with any subset of the feature packages.

## Install

```bash
composer require axiomasoft/commerz-sales
```

Requires the core. Provider `Commerz\Sales\SalesServiceProvider`. A CRM that
sells services installs it with the core and pricing only
([embedding in a CRM](../../getting-started/crm-embedding)).

## Configuration

`--tag=commerz-sales-config` → `config/commerz-sales.php`.

| Key | Default | Meaning |
|---|---|---|
| `carts.max_lines` | `250` | Lines in one cart (1..10000) |
| `carts.max_quantity` | `100000` | Quantity of one line (1..1000000) |
| `checkout.require_delivery` | `true` | Lines that require shipping need a delivery method; `false` for a CRM without shipping |
| `checkout.number_generator` | `sequence` | Key of the `order_number_generator` contribution |
| `orders.payment_due_minutes` | `4320` | Minutes to pay an order placed from a `cart` (`null` = no deadline); drafts have none |
| `orders.expire_batch` | `100` | Orders expired in one pass (1..1000) |
| `payments.lease_seconds` | `120` | Lease of a network operation (10..3600); keep above the gateway's HTTP timeout |

## Root and facade

`Commerz\Sales\Sales` (facade `Commerz\Sales\Facades\Sales`):

| Accessor | Methods |
|---|---|
| `carts()` | `create`, `addLine`, `setLineQuantity`, `removeLine`, `enterCode`, `removeCode`, `setCustomer`, `setAddress`, `selectDelivery`, `clearDelivery`, `find`, `list`, `quote`, `deliveryOptions` |
| `checkout()` | `place` |
| `orders()` | `draft`, `find`, `findByNumber`, `list`, `history`, `cancel`, `cancelLines`, `expireDue` |
| `payments()` | `initiate`, `recordEvent`, `reconcile`, `find`, `list` |
| `fulfillments()` | `create`, `ship`, `book`, `markDelivered`, `find`, `list` |
| `returns()` | `request`, `receive`, `cancel`, `find`, `list` |
| `refunds()` | `issue`, `recordEvent`, `reconcile`, `find`, `list` |

A cart is of kind `cart` (a buyer's) or `draft` (an operator's, no payment
deadline). Its channel and currency never change.

## Order model

An order is a **snapshot**: quoted lines, adjustments, taxes and totals are
copied in and never recomputed. It has three independent states:

| State | Values |
|---|---|
| `OrderState` | `Placed`, `Completed`, `Cancelled` |
| `PaymentState` | `Unpaid`, `Authorized`, `PartiallyPaid`, `Paid`, `PartiallyRefunded`, `Refunded` |
| `FulfillmentState` | `Unfulfilled`, `PartiallyFulfilled`, `Fulfilled`, `PartiallyReturned`, `Returned` |

Per line: `ordered = cancelled + fulfilled + outstanding`, `returned ≤ fulfilled`.

## Placing an order

`Checkout::place(new PlaceOrder($cart, $expectedTotal))` re-runs the quote, compares
the total to what the buyer accepted (`QuoteChanged` on a difference), then in
one transaction inserts the order, reserves stock and consumes promotion usage.
It is idempotent: a retry never creates a second order. See
[Invariants](../../architecture/invariants#order-placement-transaction).

## Payments, refunds and gateways

A `PaymentGateway` is called only by the payment and refund workflows, outside
transactions, with the journal `reference` as the provider's idempotency key.
`PaymentDeclined` is a final rejection; any other exception is an unknown
outcome settled by `find`. A provider notification enters through
`Payments::recordEvent` with a semantic event id and applies at most once
(`PaymentEventType`: `Authorized`, `Captured`, `Failed`, `Cancelled`). A refund
returns money, never goods; stock returns only when a return is received with
`restock`. See [payment gateway](../../how-to/payment-gateway).

## Kinds

| Kind | Contract | Built-in keys |
|---|---|---|
| `payment_gateway` | `PaymentGateway` (`key`, `initiate`, `find`, `refund`, `findRefund`) | `manual` |
| `order_number_generator` | `OrderNumberGenerator` (`key`, `next`) | `sequence` |

Plugin `commerz.sales`. The `manual` gateway is for offline payment and
invoices: money is confirmed by an operator's event. `sequence` is one
PostgreSQL sequence plus the store prefix; gaps are fine and it is not an
invoice number.

## Tables

Prefix + `carts`, `cart_lines`, `orders`, `order_lines`, `order_events`,
`payments`, `payment_events`, `refunds`, `fulfillments`, `fulfillment_lines`,
`returns`, `return_lines`, sequence `order_number_seq`.

## Commands

| Command | |
|---|---|
| `commerz:sales:expire-orders` | expire unpaid orders past `payment_due_at` (`--limit`) |

An order with a payment attempt is skipped.

## Events

Domain events: `OrderPlaced`, `OrderCancelled`, `OrderCompleted`,
`OrderPaymentStateChanged`, `PaymentRequiresRefund`, `PaymentStatusChanged`,
`RefundStatusChanged`, `FulfillmentCreated`, `FulfillmentShipped`,
`ReturnReceived`. Outbox events `sales.order_placed`, `sales.order_cancelled`,
`sales.order_completed`, `sales.payment_state_changed`,
`sales.payment_requires_refund`, `sales.fulfillment_shipped`,
`sales.return_received`, `sales.refund_succeeded`. See the
[events reference](../../reference/events).

API reference: [sales](../../reference/api/sales).
