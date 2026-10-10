# Invariants and locking

Commerz promises business invariants and enforces each one at a single owner:
stock never goes negative, a reservation is held at most once, a promotion
limit is never exceeded concurrently, an order is placed once, a refund never
exceeds the capture. The full registry — owner, enforcement point, closed
bypass, test — is [Invariants](../architecture/invariants).

## How they are enforced

- **In PostgreSQL first.** `CHECK` constraints, `UNIQUE` and partial unique
  indexes, `EXCLUDE USING gist` for non-overlapping price and tax windows,
  conditional `UPDATE … RETURNING` for counters. PostgreSQL ≥ 16 only; SQLite
  never proves locking.
- **One writer per table.** Packages do not write to tables owned by another
  package; inside a package a single class writes each aggregate
  (`StockLedger`, `OrderWriter`, `OrderLedger`, …). The architecture tests
  enforce it.
- **Counters only where there are limits.** A promotion without limits is never
  written to by a placement.
- **Plugins are not trusted.** Stage and rule results are validated
  (`DiscountGuard`, `TaxResultValidator`, allocation plan checks); a promotion
  applies whole or not at all.

## Lock order

Every multi-resource operation locks in one fixed global order:

> cart/order → child documents of the order → stock levels (by `stock_item_id`,
> `location_id`) → coupons → promotions → customer usages → payments → refunds

Each package adds its own finer order inside it (inventory, catalog, pricing,
promotions, shipping, sales — see
[Invariants](../architecture/invariants#global-lock-order)). A new operation
follows the order; a violation is a defect. `lock_timeout` is 3 s.

## What it means for you

- **Repeat whole operations, not parts.** A lock timeout or deadlock rolls the
  transaction back and surfaces as an exception; `Transactions::run` does not
  retry. Repeating a keyed command is safe.
- **Do not hold Commerz rows open.** Do not wrap Commerz calls in a long host
  transaction that also locks your own tables in an order of your choosing.
- **Network calls live outside.** Gateways and carriers are called by Commerz
  after commit, outside transactions; never from inside one.
- **Prove it.** The repository's concurrency suite runs N parallel processes on
  PostgreSQL against stock, promotion limits, order placement and payment
  events, with deadlock detection and a positive control. The
  [testkit](../packages/testkit/) exports `Concurrency::run()` for your own.
