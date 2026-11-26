# Commerz B2B (`axiomasoft/commerz-b2b`)

Enterprise B2B commerce addon for Commerz: multi-level business units, associates, spending limits, approval workflows, payment terms, credit accounts, and 3-stage negotiated quotes.

## Core Guarantees

- **3-stage B2B Quotes (`QuoteRequest -> StagedQuote -> NegotiatedQuote`)**: Buyers submit RFQs (from a cart or line items), sales reps stage custom unit prices and `validUntil` deadlines, buyers can `requestRenegotiation`, and accepted quotes convert to `Order` at locked unit prices.
- **Payment Terms & Credit Limits**: Supports `Net15`, `Net30`, `Net60`, and multi-milestone deposit schedules allocated without rounding drift; enforces credit limits atomically (`INV-B2B-CREDIT-001`) and forbids combining `PaymentTerms` with recurring `SellingPlan` subscriptions.
- **Corporate Hierarchy & Approval Flows**: Materialized-path `BusinessUnit` trees with inherited `Associate` roles (`admin`, `approver`, `buyer`, `viewer`), per-order spending caps, and multi-approver `ApprovalFlow` gates when budget thresholds are exceeded.
