Two defects break money and expiry invariants. The rest of the P7.4/P7.5 protocol holds.

## Findings

### 1. High — a webhook during `initiate` drops a captured gateway result and frees the balance

`packages/sales/src/Payments/Internal/PaymentJournal.php:205`

`finish` for an initiate returns the current row without applying the gateway result whenever the status is no longer `initiating`, even when `attempts` is still the attempt the gateway call was made under. A `failed` or `cancelled` webhook does not bump `attempts` and is excluded from the balance, so the synchronous `captured` result is discarded and a second initiate for the full amount is accepted. `PaymentTransitions` would have applied that capture (`failed → captured` is allowed). Reconcile is already fenced by the `attempts` check, because it bumps the attempt before calling the gateway. `PaymentJournalFenceTest.php:65` locks this guard in by flipping the status to `pending` without changing `attempts`, and never runs it through `PaymentWorkflow`.

Reproduction (PHP 8.4, Postgres `127.0.0.1:54329`): one worker calls `initiate` on `recording` with `sleep_ms` 800 and `status` `captured`; the other, once the initiate line is in the gateway log, records `PaymentEventType::Failed` for that reference; the parent then initiates again.

```text
-    'sync' => 'captured captured=10000',
+    'sync' => 'failed captured=0',
     'webhook' => 'applied failed',
     'rows' => [
         ['gateway' => 'recording', 'status' => 'failed', 'amount_minor' => 10000, 'captured_minor' => 0],
+        ['gateway' => 'manual', 'status' => 'pending', 'amount_minor' => 10000, 'captured_minor' => 0],
     ],
-    'second' => 'Commerz\Sales\Payments\Exceptions\PaymentExceedsBalance',
+    'second' => 'pending:10000',
```

The same guard drops a synchronous `captured` when the webhook was `authorized`. That case keeps the hold (an authorized attempt still counts as the full amount) and leaves the payment authorized.

### 2. High — an order that owes nothing stays `unpaid` and is expired

`packages/sales/src/Common/Internal/OrderWriter.php:71` and `packages/sales/src/Orders/Internal/OrderExpiry.php:51`

Placement always stores `payment_state = unpaid`. `OrderStatus::derive` (`OrderStatus.php:148`) would mark `amount_due = 0` as `paid`, and the P7.4 deviation only skips `refresh` at placement to avoid extra journal rows. Expiry selects and rechecks `payment_state = unpaid` (`OrderExpiry.php:81`) and never looks at `amount_due`. A zero-total cart order is cancelled when its deadline passes, which releases the reservation and restores promotion usage. `OrderStatusDeriveTest.php:21` covers the pure function only.

Reproduction: place a service at `0.00`, move `payment_due_at` one minute into the past, call `expireDue`.

```text
 'due' => 0,
-    'payment_before' => 'paid',
-    'state_after' => 'placed',
-    'payment_after' => 'paid',
+    'payment_before' => 'unpaid',
+    'state_after' => 'cancelled',
+    'payment_after' => 'unpaid',
```

Cart orders get `payment_due_at = now + payment_due_minutes` (default 4320), so the same order is a candidate once that deadline passes.

## Checked, no defect

- `PaymentTransitions` is monotonic. `failed` and `cancelled` stay final except for `captured`, matching the logged P7.5 deviation. A repeated or smaller capture does not change `captured_minor`.
- A gateway exception other than `PaymentDeclined` is stored as `unknown`. `find === null` becomes `failed` only for `initiating` or `unknown`, and `PaymentTransitions` then refuses to fail `authorized` or `captured`.
- The gateway runs outside a transaction. Tx A reserves the balance under the order lock. The inbox claim runs before `PaymentTransitions::apply`, and the fingerprint is a pure function of type, both locators, and amount.
- Expiry uses `FOR UPDATE SKIP LOCKED`, rechecks under the lock, and skips a payment in `initiating`, `pending`, `requires_action`, `unknown`, or `authorized`.
- Lock order is order, then payment, then inbox. Cancel and expiry take the order, then stock, then promotions. Those paths share the order lock first.
- Public types outside `Internal` carry `#[Api]` or `#[Spi]`. Signatures do not use Eloquent or `float`. Modules do not import another module's `Internal`. The provider binds implementations.
- `payment_requires_refund` is published only from `OrderStatus::refresh`, once per growth of `captured − refunded − amount_due`.

## Question

A capture of half the attempt frees the rest of the balance (`committed` uses `captured_minor` once the status is `captured`). A second payment takes that remainder, and a later larger capture on the first attempt grows `captured`. Reproduced on a 10000 order: the second payment is 5000, the order ends at `captured` 15000, and one `sales.payment_requires_refund` is written for 5000. Is that accepted overpay, given the P7.4 rule that the refund event covers growth of money held over the amount due?

## Residual risk

A reconcile that waits out the lease can still mark a slow initiate `failed` from `find === null` and then fence off the late captured response, because that path bumps `attempts`. The design accepts this when `lease_seconds` is longer than the gateway HTTP timeout. The partial-capture growth above is signaled, not silent. I did not re-run the Sales suite; the three cases above were run as a throwaway Pest file under PHP 8.4.

Verdict: fail
