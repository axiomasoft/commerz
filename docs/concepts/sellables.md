# Sellables

A **sellable** is anything a cart line can point at. Cart and order code never
knows what it is; it only holds a `SellableRef` (`type:id`) and a **snapshot**.

```text
variant:0198…       a catalog variant            (provider key `variant`, catalog)
crm.service:audit   a service in a CRM           (provider key `crm.service`, the host)
```

## The snapshot

A `SellableProvider` serves the refs of one type and returns, per ref, a
`SellableSnapshot`:

| Field | |
|---|---|
| `ref`, `sku`, `name` | identity and display |
| `kind` | `SellableKind`: `Physical`, `Digital`, `Service` |
| `requiresShipping` | whether a delivery method is needed |
| `purchasable`, `unavailableReason` | not purchasable is still returned; callers decide |
| `basePrice`, `taxClass` | the sellable's own price and tax class |
| `weightGrams`, `options`, `attributes`, `categories` | data other modules use |

Providers are read-only: no writes, and no network inside a transaction. A ref
the provider does not know is left out of the result and the `Sellables` router
reports it as unavailable (`SellableUnavailable`).

## Why snapshots

An order line stores the snapshot — name, SKU, options, unit price, tax,
allocated discounts, stock recipe — independently of the live catalog.
Re-pricing live data never changes a placed order (ORD-02), and a CRM can
delete or rename its own entity without breaking history.

## Wiring a provider

A provider is a contribution of kind `sellable_provider`, registered by a
plugin: `$registrar->contribute(SellableProvider::KIND, MyProvider::class)`. The catalog
registers `variant`; see [a custom sellable provider](../how-to/custom-sellable-provider).

## Channel availability

The catalog's `variant` provider reports per channel and `context.at` why a
variant is not purchasable: `not_listed`, `not_available_yet`,
`no_longer_available`. A cart refuses a sellable that is not purchasable in its
channel (CTX-01).
