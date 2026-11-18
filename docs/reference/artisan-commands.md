# Artisan commands

Registered by the packages' service providers; they appear in `php artisan list`
under the `commerz` namespace. Operational advice: [commands](../operations/commands)
and [run the workers](../how-to/run-workers).

## commerz:install

Publish the core configuration and migrate. Package: core.

```text
commerz:install [--force] [--no-migrate]
```

| Option | Description |
|---|---|
| `--force` | Overwrite the published config and migrate in production |
| `--no-migrate` | Only publish the config |

## packman:doctor

Check the Commerz configuration, install fingerprint and plugin registry.
Package: core. Exit `1` if any check fails.

```text
packman:doctor
```

## packman:plugins

List the compiled plugins and their contributions, in load order. Package: core.

```text
packman:plugins [--json]
```

| Option | Description |
|---|---|
| `--json` | Print machine-readable JSON |

## commerz:outbox:relay

Deliver recorded integration events to listeners and outbox consumers. Package:
core. Refuses to run inside a database transaction; exits `2` for an invalid `--batch`.

```text
commerz:outbox:relay [--batch=100] [--once]
```

| Option | Description |
|---|---|
| `--batch=100` | Messages claimed per round (1..1000) |
| `--once` | Deliver what is ready now, then exit |

Without `--once` it runs until SIGTERM or SIGINT (with `pcntl`).

## commerz:operations:prune

Delete expired Commerz idempotency receipts and delivered outbox messages older
than `commerz.operations.receipt_ttl_days`. Package: core. Deletes in chunks, so
a large backlog never holds one long lock.

```text
commerz:operations:prune
```

## commerz:inventory:reconcile

Compare the stock ledger, levels, reservations and transfers. Package:
inventory. Exit `1` on any discrepancy.

```text
commerz:inventory:reconcile [--repair-reserved]
```

| Option | Description |
|---|---|
| `--repair-reserved` | Rebuild `reserved` of the levels from their reservation lines |

## commerz:inventory:expire-reservations

Release expired reservations. Package: inventory.

```text
commerz:inventory:expire-reservations [--limit=]
```

| Option | Description |
|---|---|
| `--limit=` | Reservations to close in this run (1..10000); defaults to `commerz-inventory.expiry_batch` |

## commerz:promotions:reconcile

Compare promotion and coupon usage counters with the usage log and list rules no
plugin can run. Package: promotions. Read-only; exit `1` on any discrepancy.

```text
commerz:promotions:reconcile
```

## commerz:sales:expire-orders

Expire unpaid orders past their payment deadline. Package: sales. Orders with a
payment attempt are skipped.

```text
commerz:sales:expire-orders [--limit=]
```

| Option | Description |
|---|---|
| `--limit=` | Orders to expire in this pass, 1..1000 (default `commerz-sales.orders.expire_batch`) |
