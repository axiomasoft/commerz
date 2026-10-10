# Run the workers

Commerz needs a few recurring processes. All are Artisan commands; none runs by
itself.

| Command | Kind | Needed when |
|---|---|---|
| `commerz:outbox:relay` | long-running worker | you consume integration events, or listen to `IntegrationEventDelivered` |
| `commerz:operations:prune` | scheduled, daily | always: receipts and delivered outbox messages accumulate |
| `commerz:inventory:expire-reservations` | scheduled, every minute or few | inventory with expiring reservations |
| `commerz:sales:expire-orders` | scheduled, every few minutes | unpaid orders have a deadline (`orders.payment_due_minutes`) |
| `commerz:inventory:reconcile` | scheduled, nightly | inventory: consistency check |
| `commerz:promotions:reconcile` | scheduled, nightly | promotions: consistency check |

## The outbox relay

```bash
php artisan commerz:outbox:relay              # runs until SIGTERM / SIGINT
php artisan commerz:outbox:relay --once       # deliver what is ready, then exit
php artisan commerz:outbox:relay --batch=200  # messages per round (1..1000)
```

Run it under a process supervisor (systemd, Supervisor, a Kubernetes
deployment). With `pcntl` it finishes the current batch and exits on SIGTERM.
Several relays can run at once: batches are claimed with `FOR UPDATE SKIP
LOCKED`. The relay refuses to run inside a database transaction.

Delivery is at least once; consumers deduplicate by `eventId`. See
[events and outbox](../concepts/events-and-outbox).

## Scheduling

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('commerz:inventory:expire-reservations')->everyMinute()->withoutOverlapping();
Schedule::command('commerz:sales:expire-orders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('commerz:operations:prune')->daily();
Schedule::command('commerz:inventory:reconcile')->dailyAt('03:00');
Schedule::command('commerz:promotions:reconcile')->dailyAt('03:10');
```

`--limit` on the expiry commands caps one pass; they default to
`commerz-inventory.expiry_batch` and `commerz-sales.orders.expire_batch` and are
safe to run concurrently with checkout (they skip locked rows).

## Alerting

The reconcile commands exit non-zero on any discrepancy: alert on it. See
[reconciliation](../operations/reconcile).
