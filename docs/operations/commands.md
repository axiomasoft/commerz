# Commands

All commands are Artisan commands registered by the packages' service
providers. Descriptions of every option are in the
[Artisan command reference](../reference/artisan-commands).

| Command | Package | Run | Purpose |
|---|---|---|---|
| `commerz:install` | core | once, on deploy | publish config, migrate |
| `commerz:doctor` | core | on deploy, in CI | verify configuration, fingerprint, plugins |
| `commerz:plugins` | core | on demand | list plugins and contributions |
| `commerz:outbox:relay` | core | worker | deliver integration events |
| `commerz:operations:prune` | core | daily | delete expired receipts and delivered outbox messages |
| `commerz:inventory:expire-reservations` | inventory | every minute | release expired reservations |
| `commerz:inventory:reconcile` | inventory | nightly | verify stock invariants |
| `commerz:promotions:reconcile` | promotions | nightly | verify promotion counters |
| `commerz:sales:expire-orders` | sales | every few minutes | expire unpaid orders |

Scheduling and supervision: [run the workers](../how-to/run-workers).

## Exit codes

| Code | Meaning |
|---|---|
| `0` | success |
| `1` | failure: a failed check or a discrepancy (doctor, reconcile commands) |
| `2` | invalid input (an option out of range) |

## Safety

- The commands that write — relay, prune, the expiry sweepers — take row locks
  with `SKIP LOCKED` or bounded batches, so they run beside live traffic and beside
  each other.
- The reconcile commands are read-only, except `commerz:inventory:reconcile
  --repair-reserved`, which rebuilds only `reserved` from the reservation lines.
- Nothing network-bound runs from a command inside a transaction.
