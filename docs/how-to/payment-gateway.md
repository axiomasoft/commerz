# A payment gateway

A gateway is a contribution of kind `payment_gateway`. Sales calls it only from
the payment and refund workflows, **outside database transactions**, and never
trusts the answer blindly.

```php
use Commerz\Sales\Gateways\Contracts\PaymentGateway;

final class AcmePayGateway implements PaymentGateway
{
    public function key(): string { return 'acme.pay'; }

    public function initiate(GatewayPaymentRequest $request): GatewayPaymentResult { /* … */ }
    public function find(string $reference): ?GatewayPaymentResult { /* … */ }
    public function refund(GatewayRefundRequest $request): GatewayRefundResult { /* … */ }
    public function findRefund(string $reference): ?GatewayRefundResult { /* … */ }
}
```

Register it in a plugin that lists `commerz.sales` in `requires` (sales declares the
kind): `$registrar->contribute(PaymentGateway::KIND, AcmePayGateway::class)`.

## The rules

1. **`reference` is your idempotency key.** `initiate` and `refund` must pass
   `$request->reference` to the provider as its idempotency key or metadata, so
   a repeated call never charges twice.
2. **A final refusal is `PaymentDeclined`.** Throw it only when the provider
   certainly did nothing. The payment turns `failed`.
3. **Any other exception means unknown.** A timeout or a broken connection leaves
   the attempt `unknown`; the system settles it later with `find($reference)`. Do
   not catch and convert it into a decline.
4. **`find` returns `null` only when the provider confirms it knows no such
   attempt.** If you cannot tell, throw.
5. **Do not mutate Commerz.** The result is data: `GatewayPaymentResult(status,
   externalId, authorized, captured, actionUrl, meta)`.

Statuses: `Pending`, `RequiresAction`, `Authorized`, `Captured`, `Failed`,
`Cancelled` (payments); `Pending`, `Succeeded`, `Failed` (refunds). When a
customer must act (3-D Secure, a redirect), return `RequiresAction` with an
`actionUrl`.

## Provider notifications (webhooks)

Your HTTP endpoint is the host's. It verifies the provider's signature, maps the
notification to Commerz, and records it:

```php
Sales::payments()->recordEvent(new RecordPaymentEvent(
    gateway: 'acme.pay',
    externalEventId: $notification->id,        // a semantic, stable event id
    type: PaymentEventType::Captured,
    paymentReference: $reference,
));
```

The inbox is unique on `(gateway, external_event_id)`, so a repeated webhook is
ignored. Event types: `Authorized`, `Captured`, `Failed`, `Cancelled`.

## Reconcile

Payments and refunds left `unknown` or stuck in a lease are settled with
`Sales::payments()->reconcile(...)` and `Sales::refunds()->reconcile(...)`; see
[reconciliation](../operations/reconcile). `payments.lease_seconds` must exceed
the HTTP timeout of your client.

## Manual gateway

`manual` is built in: no network, a payment stays pending until an operator
records `Captured`, and a refund is booked by the operator and succeeds at once.
It is what the [quick start](../getting-started/quick-start-shop) uses.
