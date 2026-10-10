# axiomasoft/commerz (core)

The core holds what every other package needs and nothing a feature package
could own: money and measures, the commerce context, identity types, the ports,
the plugin registry, stores and channels, the quote pipeline, idempotent
operations and the transactional outbox. It knows nothing about feature
packages — no class names, no contribution kinds they declare.

## Install

```bash
composer require axiomasoft/commerz
php artisan commerz:install
```

Requires PHP ^8.4, `illuminate/*` ^13 and `brick/math`. The provider
`Commerz\CommerzServiceProvider` is discovered automatically.

## Configuration

Published with `--tag=commerz-config` to `config/commerz.php`.

| Key | Default | Meaning |
|---|---|---|
| `connection` | `null` | Database connection of all Commerz tables |
| `table_prefix` | `cmz_` | Table prefix; fixed by the first migration |
| `stock` | `inventory` | `inventory` or `unlimited` |
| `customers` | `null` | `CustomerDirectory` class |
| `plugins` | `[]` | Host plugin classes |
| `operations.receipt_ttl_days` | `30` | Retention of receipts and delivered outbox messages |

## Root and facade

`Commerz\Commerz` (facade `Commerz\Facades\Commerz`):

| Accessor | Contract | Purpose |
|---|---|---|
| `stores()` | `Stores` | create, update, archive, find, list stores |
| `channels()` | `Channels` | create, update, archive channels; `assertActive` |
| `plugins()` | `PluginInspector` | the compiled plugin registry, for diagnostics |
| `quotes()` | `QuotePipeline` | run a quote on a `QuoteInput` |
| `sellables()` | `Sellables` | resolve refs to snapshots through the providers |

## Public building blocks

| Area | Types |
|---|---|
| Money and measures | `Money`, `Currency`, `Rate`, `RoundingMode`, `MoneyAllocator`, `Quantity`, `Measure`, `LocalizedText` |
| Identity | `EntityId` and typed ids, `Uuid7`, `SellableRef`, `CustomerRef`, `ActorRef`, `IdempotencyKey` |
| Context | `CommerceContext`, `ContextResolver` |
| Ports | `StockAvailability`, `StockReservations`, `StockReturns`, `PriceResolver`, `PromotionUsage`, `DeliveryOptions`, `ShipmentBooking`, `CustomerDirectory`, `SellableProvider` |
| Plugins | `CommerzPlugin`, `PluginManifest`, `PluginRegistrar`, `Contributions`, `PluginInspector` |
| Quotes | `QuotePipeline`, `QuoteStage`, `QuoteAnchor`, `QuoteInput`, `QuoteState`, `QuoteResult` |
| Operations | `Transactions`, `OperationReceipts`, `CommittedEvents` |
| Outbox | `Outbox`, `OutboxConsumer`, `IntegrationEvent` |
| Errors | `CommerzException`, `ErrorCategory`, `InvalidCommand`, `NotFound`, `IdempotencyConflict`, `InvalidConfiguration`, `UnsupportedContribution` |
| Schema | `TableNames`, `CommerzMigration`, `ConstraintNames`, `SchemaOwnership` |

## Plugin and kinds

Plugin `commerz.core`. Declared kinds: `sellable_provider`, `quote_stage`,
`outbox_consumer`. Built-in stage keys `base` and `totals`.

## Tables

Prefix + `stores`, `channels`, `schema_state`, `operation_receipts`,
`outbox_messages`.

## Commands

| Command | |
|---|---|
| `commerz:install` | publish the config and migrate |
| `commerz:doctor` | check configuration, database fingerprint and plugins |
| `commerz:plugins` | list the compiled plugins (`--json`) |
| `commerz:outbox:relay` | deliver outbox events (`--batch`, `--once`) |
| `commerz:operations:prune` | delete expired receipts and delivered outbox messages |

## Events

Domain events `StoreCreated`, `ChannelCreated`, `ChannelUpdated`, and
`IntegrationEventDelivered` from the relay. See the
[events reference](../../reference/events).

## Port defaults

When a feature package is absent the core serves defaults — unlimited stock,
the sellable's base price, no promotions, guests only, no delivery. See
[ports and plugins](../../concepts/ports-and-plugins).

API reference: [core](../../reference/api/core).
