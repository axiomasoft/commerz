# Commerz Subscriptions (`axiomasoft/commerz-subscriptions`)

Recurring subscription contracts, billing cycle scheduling, dunning retry policies, and automatic renewal order creation for Commerz, built on core `Commerz\Sellable\SellingPlan`.

## Core Guarantees

- **Strict L2 isolation**: Depends only on `axiomasoft/commerz` (plus `packman/*`, `illuminate/*`, and `brick/math`), reacting to `orders.order_placed` (and `sales.order_placed`) through the transactional Outbox with idempotent `event_id` and origin-line deduplication.
- **Explicit contract state machine**: `SubscriptionContract` transitions cleanly across `active`, `paused`, `past_due`, and terminal `canceled` states under row-level locks.
- **Deterministic `BillingCycle` & dunning `RetryPolicy`**: Every recurring cycle is scheduled via `Commerz\Time\Contracts\Clock`; transient renewal failures transition the contract to `past_due` and schedule bounded backoff retries before applying the configured exhaustion action.
- **Cycle-aware renewal quoting & order creation**: Renewal cycles quote lines through core `QuotePipeline` (applying cycle-specific `SellingPlan` `PricingPolicy` rules) and create idempotent renewal orders through the `RenewalOrderCreator` SPI.
