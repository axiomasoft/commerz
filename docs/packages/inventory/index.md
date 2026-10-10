# axiomasoft/commerz-inventory

Stock locations, stock items and their levels, reservations and transfers. It
implements the stock ports of the core, so with it installed a sale reserves
real stock atomically with the order.

## Install

```bash
composer require axiomasoft/commerz-inventory
```

Requires the core. Provider `Commerz\Inventory\InventoryServiceProvider`. It
overrides the core's `StockAvailability`, `StockReservations` and
`StockReturns`; keep `commerz.stock = inventory`.

## Configuration

`--tag=commerz-inventory-config` → `config/commerz-inventory.php`.

| Key | Default | Meaning |
|---|---|---|
| `allocation_strategy` | `priority` | Key of the `allocation_strategy` contribution: `priority`, `single_location` or a plugin key |
| `unlinked_sellables` | `unavailable` | A sellable with no stock item link: `unavailable` or `unlimited` |
| `expiry_batch` | `500` | Reservations the expiry sweeper releases per pass (1..10000) |

## Root and facade

`Commerz\Inventory\Inventory` (facade `Commerz\Inventory\Facades\Inventory`):

| Accessor | Methods |
|---|---|
| `locations()` | `create`, `update`, `archive`, `restore`, `find`, `findByCode`, `list` |
| `items()` | `create`, `update`, `archive`, `restore`, `find`, `findBySku`, `list`, `link`, `unlink`, `links` |
| `sources()` | `assign`, `unassign`, `list` — which locations serve a channel, by priority |
| `stock()` | `receive`, `adjust`, `count`, `setSafetyStock`, `level`, `list` |
| `movements()` | `list` — the append-only ledger |
| `reservations()` | `reserve`, `consume`, `find`, `expire` |
| `availability()` | `StockAvailability`: what can be sold |
| `transfers()` | `create`, `dispatch`, `receive`, `cancel`, `find`, `list` |

A stock item has a policy `tracked`, `backorder` or `untracked`. A sellable is
**linked** to items with a quantity per unit; several links make a kit.
Quantities are integers in the item's base unit.

## Kinds

| Kind | Contract | Built-in keys |
|---|---|---|
| `allocation_strategy` | `AllocationStrategy` (`key`, `allocate`, `capacity`; pure) | `priority`, `single_location` |

Plugin `commerz.inventory`. A plan from a strategy is validated against the
locked levels (`InvalidAllocationPlan`).

## Tables

Prefix + `stock_locations`, `stock_items`, `stock_item_links`, `stock_levels`,
`stock_movements`, `stock_sources`, `stock_reservations`,
`stock_reservation_lines`, `stock_transfers`, `stock_transfer_lines`.
Every change of `on_hand` is exactly one movement row.

## Commands

| Command | |
|---|---|
| `commerz:inventory:reconcile` | compare ledger, levels, reservations and transfers; `--repair-reserved` rebuilds `reserved` from the lines |
| `commerz:inventory:expire-reservations` | release expired reservations (`--limit`) |

## Events

Domain events: `StockReserved`, `StockConsumed`, `StockReleased`,
`StockLevelChanged`, `StockShortfall`, `TransferUpdated`. Outbox event
`inventory.level_changed`, one per changed level carrying its final state.
See the [events reference](../../reference/events).

## Guarantees

`reserved ≥ 0`, `on_hand ≥ 0`; a reservation of a tracked item never exceeds
`on_hand − safety_stock`; one demand holds at most one set of reservations and
`reserve` is idempotent per demand. Locks are taken in a fixed order in one
statement. See [Invariants](../../architecture/invariants).

API reference: [inventory](../../reference/api/inventory).
