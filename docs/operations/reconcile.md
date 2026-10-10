# Reconciliation

Invariants are enforced at write time. Reconciliation is the second line: it
re-checks stored state against the journals that explain it, reports any
discrepancy, and almost never repairs it by itself. Run the checks on a
schedule and alert on a non-zero exit.

## Stock

```bash
php artisan commerz:inventory:reconcile
php artisan commerz:inventory:reconcile --repair-reserved
```

Reads one read-only `REPEATABLE READ` snapshot, without locks, and compares:

| Check | Compares |
|---|---|
| `ledger_sum` | `on_hand` movement deltas to the level |
| `ledger_balance` | each movement's `balance_after` to the running total |
| `reserved_sum` | `reserved` of a level to the outstanding reservation lines |
| `reservation_state` | header state to its lines |
| `transfer_state` | transfers to their lines |

Exit 1 on any discrepancy. `--repair-reserved` rebuilds **only** `reserved` from
the lines. `on_hand` is never repaired automatically: correct it with a stock
count, which writes a movement.

## Promotions

```bash
php artisan commerz:promotions:reconcile
```

Compares the usage counters of promotions, coupons and customers with the usage
log, and lists rules no installed plugin can run (`unsupported`). Read-only: a
counter is rebuilt by changing the limit (`UpdatePromotion`, `SetCouponLimit`),
which recomputes it from the log under a lock.

## Payments and refunds

Not a command, because they need the provider. An attempt left `unknown` (a
timeout, a crash) or past its lease is settled through the gateway:

```php
Sales::payments()->reconcile(new ReconcilePayment($paymentId));
Sales::refunds()->reconcile(new ReconcileRefund($refundId));
```

`reconcile` asks the gateway's `find($reference)`; an attempt the provider never
saw becomes `failed`; a final payment is returned without a call. Call it from a
scheduled job over attempts that stayed `unknown`, outside a transaction.

## Shipments

A booking left `unknown` or past its lease is recovered by booking again: the
journal calls the carrier's `find` first. See [carrier](../how-to/carrier).

## Orders waiting for money

`commerz:sales:expire-orders` cancels unpaid orders past `payment_due_at`. An
order with a payment attempt is skipped; if a capture arrives after a
cancellation, `sales.payment_requires_refund` is emitted so you refund it.

## What to do with a discrepancy

1. Do not repair by hand-editing counters. Find the operation that disagrees
   (movements, order events, usage log are append-only).
2. For stock, count the level; for promotion counters, re-set the limit.
3. A discrepancy that reproduces is a defect: keep the report.
