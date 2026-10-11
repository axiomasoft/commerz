A failure webhook that lands during the gateway call frees the captured amount, and the attempt’s own success or pending answer is then dropped. A second `issue` of the same amount is sent to the gateway.

## Finding

**High.** `packages/sales/src/Refunds/Internal/RefundJournal.php:278` (`RefundStatus::isFinal` at `packages/sales/src/Refunds/Enums/RefundStatus.php:25`). `failed` is final, so `transition(failed, succeeded|pending)` is null. `finish` still runs (attempts are unchanged by an event), writes the provider id from the success answer, and leaves the status `failed`, which no longer counts against refundable. The next full refund is a new `refund:{id}` and the gateway is asked again. The books keep `payments.refunded_minor` at one captured amount.

This is the logged P7.8 deviation that a final status never moves. It breaks the “no refund applied twice” rule. The same race is handled for payments by applying `captured` from `failed` (`PaymentTransitions::capture`, and `InitiatePaymentTest` “keeps the capture the gateway answered after a webhook moved the attempt on”). `RecordingGateway::duringRefund` was added for this race and has no caller in the suite.

**Reproduction** (throwaway Pest, not in the repo; PHP 8.4, PostgreSQL `127.0.0.1:54329`). A `recording` payment is captured for 200.00 RUB. During `refund`, `recordEvent(..., succeeded: false)` runs, then the gateway returns its default `Succeeded`:

```text
first: failed
second: succeeded
refunded_minor: 20000
gateway_calls:
  refund refund:01a124d3-aa50-73ec-9dee-51ca016927bb
  refund refund:01a124d3-aa86-7454-9c30-212490b775f4
rows:
  succeeded  external=ext-refund:01a124d3-aa86-…  error=null
  failed     external=ext-refund:01a124d3-aa50-…  error=refund_failed_at_gateway
```

The failed row’s `ext-refund:…` id comes only from the success result, so that answer was applied and its status change was discarded. The safe outcome is one gateway call, status `succeeded`, `refunded_minor` 20000, and `RefundExceedsBalance` on the second issue.

The same run with the sync answer `pending` ends `first: failed`, `second: succeeded`, `calls: 2`. The provider still has the first refund in progress while the second is sent. A later `refund_succeeded` webhook for the failed reference hits the same `isFinal()` arm and cannot repair it.

## Question

`refundsOrderFully` restores usage when succeeded refunds plus this amount reach `sum(captured_minor)`, which is the design formula. A partial capture that is fully refunded therefore restores usage while the order stays payable with the discount still on the snapshot. Is that the intended reading of “fully refunded”?

## What holds

Refundable is `captured − Σ refunds not failed`, computed under the order lock and `FOR UPDATE` on the payment in the same transaction as the insert. `unknown` and `pending` keep holding the amount. A decline or a not-found on `initiating|unknown` becomes `failed` and frees it. The gateway is called only after that transaction commits, and only from `RefundWorkflow`. Reconcile takes a new attempt and fences the write on `attempts`. The inbox claim is before the transition. Promotion `restore(Refunded)` runs only on the transition to `succeeded` that covers captured, before the payment lock, on the same connection via a savepoint. Refunds do not reference stock. Public types carry `#[Api(since: '0.1')]`, money is `Money`, and error codes match `sales-design#api`. The `RefundEventResult` deviation and the refunded-sum writer in `RefundJournal` do not break those invariants.

Verdict: fail

Residual risk: if `lease_seconds` is shorter than the HTTP call, reconcile can mark not-found `failed` and the in-flight success is fenced out by `attempts`. That is the shared lease rule. `CHECK (refunded_minor <= captured_minor)` stops the books from storing more than was captured, so a second gateway refund does not show up as a second succeeded sum.