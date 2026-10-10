# Operations and idempotency

Three core contracts make every mutation safe to repeat and safe to compose.

## Transactions

```php
$result = $transactions->run(fn () => $work(), lockTimeoutMs: 3000);
```

`Transactions::run` opens a transaction on the Commerz connection
(`commerz.connection`) and sets `lock_timeout` (3 s by default). If a
transaction is already open — the host's or an outer `run()` — the work joins it
in a **savepoint**; a failed nested operation rolls back its own writes and
pending events even if the host catches the exception.

::: warning There is no automatic retry
On a lock timeout or a deadlock the exception reaches the caller and nothing is
repeated for you. The caller decides whether to repeat — and repeats the
**whole** operation, never a part of it. Keyed commands (below) are safe to
repeat.
:::

## Idempotent commands

`OperationReceipts::once($scope, $key, $fingerprint, $work, $encode, $decode)`
guarantees that a keyed command runs at most once per `(scope, key)`:

| Situation | Result |
|---|---|
| first call | claims the key, runs the work, stores the encoded result |
| concurrent call, same key | waits for the first transaction |
| later call, same fingerprint | returns the stored result |
| later call, different fingerprint | `IdempotencyConflict` |
| the work throws | the claim rolls back; a retry runs the work again |

Receipts live in `cmz_operation_receipts` and are pruned by age
(`commerz.operations.receipt_ttl_days`, default 30) by
`commerz:operations:prune`. Where a natural key exists it does the work
instead: a coupon `code`, a variant `sku`, a payment event id, a booking
`reference`.

## Committed events

`CommittedEvents::dispatch($event)` dispatches a Laravel event only once the
surrounding transaction commits; outside a transaction it dispatches at once, on
rollback it is dropped. The package events in the
[events reference](../reference/events) all go through it.

## Work outside transactions

Anything that talks to the network — a payment gateway, a carrier, the outbox
relay — **refuses to run inside an open transaction**, with
`sales.operation_inside_transaction` or `shipping.booking_inside_transaction`.
Otherwise a rollback could leave a real charge or a real parcel behind. These
operations are journaled commands with a lease and a reference used as the
provider's idempotency key; an unknown outcome is settled by `find(reference)`,
not guessed as failed. See [payment gateway](../how-to/payment-gateway) and
[carrier](../how-to/carrier).
