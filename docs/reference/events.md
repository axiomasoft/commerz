# Events

Commerz announces facts in two ways; see [events and outbox](../concepts/events-and-outbox).

## Integration events (outbox)

Stored in the transactional outbox with the change they describe and delivered at least once by `commerz:outbox:relay` to `outbox_consumer` contributions and as the Laravel event `Commerz\Outbox\Events\IntegrationEventDelivered`. Deduplicate by `eventId`. `aggregateRef` names what the fact is about.

| Type | Package | Aggregate | Fact |
|---|---|---|---|
| `inventory.level_changed` | inventory | `stock_level:<id>` | A stock level changed in a committed operation; one event per level and operation, carrying its final state. |
| `sales.order_placed` | sales | `order:<id>` | An order was placed. |
| `sales.order_cancelled` | sales | `order:<id>` | An order was cancelled (by an operator, by expiry, or for lack of payment). |
| `sales.order_completed` | sales | `order:<id>` | An order became fulfilled and paid. |
| `sales.payment_state_changed` | sales | `order:<id>` | The payment state of an order changed. |
| `sales.payment_requires_refund` | sales | `order:<id>` | An order holds more money than it is due, for example a capture after cancellation: refund it. |
| `sales.fulfillment_shipped` | sales | `fulfillment:<id>` | A fulfillment was shipped, by hand or through a booking. |
| `sales.return_received` | sales | `return:<id>` | A return was received. |
| `sales.refund_succeeded` | sales | `refund:<id>` | A refund succeeded. |

`version` is the payload schema version (all `1` in 0.1). Types are dotted lower-case names and are part of the SemVer surface.

## Domain events (Laravel)

Dispatched through Laravel's event dispatcher **after the transaction commits** (dropped on rollback). They run in the request or job that made the change.

### Core

| Event | When |
|---|---|
| `Commerz\Outbox\Events\IntegrationEventDelivered` | Laravel event dispatched by the outbox relay for each delivered event; same guarantees as `OutboxConsumer`. |
| `Commerz\Stores\Events\ChannelCreated` | Dispatched after the creating transaction commits. |
| `Commerz\Stores\Events\ChannelUpdated` | Dispatched after a channel changed or was archived; the view is the state after the change. |
| `Commerz\Stores\Events\StoreCreated` | Dispatched after the creating transaction commits. |

### Catalog

| Event | When |
|---|---|
| `Commerz\Catalog\Listings\Events\ProductPublished` | Dispatched after a publication (a new one or a changed window) committed. |
| `Commerz\Catalog\Listings\Events\ProductUnpublished` | Dispatched after a product was taken out of a channel and the change committed. |
| `Commerz\Catalog\Products\Events\ProductCreated` | Dispatched after the creating transaction commits. |
| `Commerz\Catalog\Products\Events\ProductUpdated` | Dispatched after a product changed (fields, attributes or archival) and the change committed; the view is the state after it. |
| `Commerz\Catalog\Variants\Events\VariantCreated` | Dispatched after the creating transaction (`create` or `generate`, one per variant) commits. |
| `Commerz\Catalog\Variants\Events\VariantUpdated` | Dispatched after a variant changed (fields, attributes, archival or restore) and the change committed; the view is the state after it. |

### Inventory

| Event | When |
|---|---|
| `Commerz\Inventory\Reservations\Events\StockConsumed` | Held units of a demand left stock as a sale (movement `sale`) in a committed operation. |
| `Commerz\Inventory\Reservations\Events\StockReleased` | Held stock of a demand went back to free stock in a committed operation. |
| `Commerz\Inventory\Reservations\Events\StockReserved` | A demand reserved stock in a committed operation (a repeated reserve that wrote nothing raises none). |
| `Commerz\Inventory\Stock\Events\StockLevelChanged` | A level changed in a committed operation; one event per level and operation, carrying its final state. `kind` is the last movement and `delta` the net on-hand change; both are null/0 when only reserved or safety stock changed. |
| `Commerz\Inventory\Stock\Events\StockShortfall` | A committed operation left the level short — `shortfall = reserved + safetyStock − onHand > 0` — and shorter than before (D21: physical truth is recorded, never refused; the shortfall is reported instead). |
| `Commerz\Inventory\Transfers\Events\TransferUpdated` | A transfer was created, dispatched, received (in part or in full) or cancelled in a committed operation. |

### Pricing

| Event | When |
|---|---|
| `Commerz\Pricing\PriceLists\Events\PriceListChanged` | A price list was created, changed, assigned to or removed from channels, archived or restored in a committed operation. |
| `Commerz\Pricing\Prices\Events\PricesChanged` | Prices of the sellables were set, rescheduled or removed in a committed operation. |
| `Commerz\Pricing\Taxes\Events\TaxRatesChanged` | A rate of the class in the zone was set, rescheduled or removed in a committed operation. |

### Promotions

| Event | When |
|---|---|
| `Commerz\Promotions\Coupons\Events\CouponsGenerated` | A batch of coupons was generated in a committed operation. |
| `Commerz\Promotions\Definitions\Events\PromotionChanged` | A promotion was created, changed, paused, resumed, archived or had its channels changed in a committed operation. |

### Shipping

| Event | When |
|---|---|
| `Commerz\Shipping\Bookings\Events\ShipmentBooked` | A carrier booked the shipment of `$reference` and the journal recorded it in a committed operation; once per reference. |
| `Commerz\Shipping\Methods\Events\MethodChanged` | A method was created, updated, had its rates or channels changed, was enabled, disabled or archived in a committed operation. |
| `Commerz\Shipping\Zones\Events\ZoneChanged` | A zone was created, renamed, had its members replaced, was archived or restored in a committed operation. |

### Sales

| Event | When |
|---|---|
| `Commerz\Sales\Fulfillments\Events\FulfillmentCreated` | Dispatched after the creation of a fulfillment commits. |
| `Commerz\Sales\Fulfillments\Events\FulfillmentShipped` | Dispatched after a fulfillment is shipped (by hand or through a booking) and the change commits. |
| `Commerz\Sales\Orders\Events\OrderCancelled` | Dispatched after the cancellation of the order commits. |
| `Commerz\Sales\Orders\Events\OrderCompleted` | Dispatched after the change that left the order fulfilled and paid commits. |
| `Commerz\Sales\Orders\Events\OrderPaymentStateChanged` | Dispatched after a change of the payment state of the order commits. |
| `Commerz\Sales\Orders\Events\OrderPlaced` | Dispatched after the placement commits. |
| `Commerz\Sales\Orders\Events\PaymentRequiresRefund` | Dispatched after a change that left the order holding more money than it is due commits: a cancellation of a paid order or of some of its lines, or a capture after the cancellation. Nothing is refunded automatically. |
| `Commerz\Sales\Payments\Events\PaymentStatusChanged` | Dispatched after a change of the status of a payment commits. |
| `Commerz\Sales\Refunds\Events\RefundStatusChanged` | Dispatched after a change of the status of a refund commits. |
| `Commerz\Sales\Returns\Events\ReturnReceived` | Dispatched after the acceptance of a return commits. |

