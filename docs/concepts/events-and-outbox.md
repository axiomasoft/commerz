# Events and outbox

Commerz tells the world about facts in two ways.

| | Domain events | Integration events |
|---|---|---|
| What | Laravel events (`OrderPlaced`, `StockLevelChanged`, …) | `IntegrationEvent` rows in the outbox |
| When | after the transaction commits | stored **in** the transaction, delivered later |
| Guarantee | in-process, lost if the process dies right after commit | at least once |
| For | the host's own listeners in the same app | integrations, other systems, queues |

The full lists are in the [events reference](../reference/events).

## The transactional outbox

`Outbox::record(IntegrationEvent)` needs an open transaction and writes the
event in the same transaction as the change it describes — so the fact exists
exactly when the change does. An `IntegrationEvent` has:

| Field | |
|---|---|
| `eventId` | lower-case UUID; **consumers deduplicate by it** |
| `type` | dotted stable name: `sales.order_placed`, `inventory.level_changed` |
| `version` | payload schema version |
| `aggregateRef` | `type:id` the fact is about, e.g. `order:0198…` |
| `payload` | JSON-encodable array |
| `occurredAt` | UTC |

## The relay

```bash
php artisan commerz:outbox:relay          # a long-running worker
php artisan commerz:outbox:relay --once   # deliver what is ready now, then exit
```

The relay claims batches with `FOR UPDATE SKIP LOCKED`, so several workers can
run side by side. It hands each event to the Laravel dispatcher as
`IntegrationEventDelivered` and to every registered `outbox_consumer`
contribution, then marks it delivered. It refuses to run inside a transaction.
A failure schedules a later retry which reaches **every** consumer again.

```php
final class SyncToCrm implements OutboxConsumer
{
    public function key(): string { return 'acme.sync_to_crm'; }

    public function consume(IntegrationEvent $event): void
    {
        if ($event->type !== 'sales.order_placed') {
            return;                               // ignore types you do not handle
        }
        // idempotent by $event->eventId
    }
}
// plugin: $registrar->contribute(OutboxConsumer::KIND, SyncToCrm::class);
```

Delivery is **at least once** and in no guaranteed order across retries, so a
consumer deduplicates by `eventId` and ignores what it does not handle. A
consumer runs outside the relay's transaction.

Delivered messages and receipts are removed after
`commerz.operations.receipt_ttl_days` by `commerz:operations:prune`; schedule
both commands ([run the workers](../how-to/run-workers)).
