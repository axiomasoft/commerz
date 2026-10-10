No confirmed defect. The committed P7.6 fulfillment flow matches the sales-design contract and the two logged deviations. Pest was not re-run; nothing in the diff needed a reproduction.

Checked against ORD-03, the lock table, and the book rules:

- `create` takes the receipt (when a key is present), then `OrderLocks::lock`, then the document, then `OrderLedger::fulfill` for every line, then `StockReservations::consume` only for `SellableKind::Physical`, with demand `order:{id}`, reference `fulfillment:{id}`, and the command's `locationCode`. A ledger or reservation failure is still inside that transaction, so the document and the ledger update roll back with it. A repeated key returns the stored id and does not consume again.
- `ship`, `book`'s write, and `markDelivered` lock the order, then the fulfillment row. `book` throws `OperationInsideTransaction` before any read and calls `ShipmentBooking` only after that, with no row lock held. The reference is `fulfillment:{id}`. A shipped or delivered document is returned without a second call. A write that finds the row already shipped keeps it when the carrier and the tracking both match, and throws `FulfillmentAlreadyShipped` otherwise, so tracking is not replaced.
- `OrderStatus::refresh` re-reads the lines. Completion still requires a paid (or refunded) order with nothing outstanding. `sales.fulfillment_shipped` and `FulfillmentShipped` are recorded only on the transition to shipped.
- Public types outside `Internal` carry `#[Api(since: '0.1')]`. No Eloquent, no `float`, no other module's `Internal`. Error codes match `sales-design#api`.

The logged deviations are implemented as described and do not break those invariants. The parcel sums weight and declared value only for `requires_shipping` lines, and a fulfillment with none of them is `FulfillmentNotBookable('nothing_to_ship')`. An idempotent ship compares the carrier and the tracking.

Verdict: pass

Residual risk: two multi-line fulfillments can still deadlock inside the stock port. Each `consume` locks that line's stock levels and holds them until the sales transaction ends, in line-id order, while reserve and a single consume lock levels by `(stock_item_id, location_id)`. That is the same per-line pattern as `cancelLines`, it aborts one transaction rather than double-consuming, and C6 only races one line under unlimited stock, so it does not show it. A manual `ship` that commits the same carrier and tracking while `book` is at the carrier also drops the new label on purpose. Separate partial parcels of one line can under-declare by a minor unit because each parcel allocates against the full line total on its own.
