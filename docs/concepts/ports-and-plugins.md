# Ports and plugins

Commerz is extended through exactly **five mechanisms**; everything else —
Eloquent model swapping, generic before/after hooks, executable configuration,
subscriptions stored in the database — is out by design.

| Mechanism | Changes | Example |
|---|---|---|
| Data | assortment, attributes, prices, promotions, warehouses, delivery zones | a new attribute without a release |
| Typed configuration | enum policies | `commerz.stock`, `commerz-inventory.allocation_strategy` |
| Plugins (contribution kinds) | new behavior under a stable key | a promotion effect, a payment gateway, a carrier |
| Port implementation | replaces a whole module | an external stock adapter; a CRM's `SellableProvider` |
| Events and outbox | reactions to facts | `sales.order_placed` → e-mail, CRM, chat |

## Ports

A **port** is a core contract that a feature package (or the host) implements.
Feature packages never call each other: sales asks `StockReservations`,
`PriceResolver`, `PromotionUsage`, `DeliveryOptions`; whichever package
implements the port answers.

| Port | Implemented by | Core default |
|---|---|---|
| `StockAvailability`, `StockReservations`, `StockReturns` | inventory | `UnlimitedStock` when `commerz.stock = unlimited`; `InvalidConfiguration` with `inventory` and no package |
| `PriceResolver` | pricing | `SnapshotPriceResolver`: the sellable's base price, else `PriceNotFound` |
| `PromotionUsage` | promotions | `NullPromotionUsage`: nothing applied |
| `DeliveryOptions`, `ShipmentBooking` | shipping | `NoDelivery`: no options, booking unsupported |
| `CustomerDirectory` | the host | guests only |
| `Sellables` / `SellableProvider` | catalog (`variant`), the host | — |

Defaults are bound with `singletonIf`; a feature provider rebinds with a plain
`singleton`. That is why a partial installation works, and why the reverse boot
order of providers does not matter. The testkit ships an in-memory fake of every
port.

## Plugins

A plugin implements `CommerzPlugin`:

```php
final class AcmePlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('acme.loyalty', '1.0.0', requires: ['commerz.promotions']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(PromotionEffect::KIND, LoyaltyDoubler::class);
    }
}
```

- The id is `vendor.name` (`[a-z0-9.-]`). `register` declares only: no I/O.
- `PluginRegistrar`: `declareKind()`, `contribute($kind, $contribution)`, and the
  sugar `sellableProvider()` and `quoteStage()`. The contribution's key comes
  from its own `key()`.
- Commerz packages register their built-ins through the same API
  (`commerz.core`, `commerz.catalog`, … `commerz.sales`); host plugins come from
  `config('commerz.plugins')`.
- `PluginGraphCompiler` orders plugins by `requires`. A duplicate id or key, an
  unknown kind, a wrong interface, an unknown required plugin or a cycle is an
  `InvalidConfiguration` at boot.
- The compiled registry (`Contributions`) is frozen: built lazily on first
  access, immutable for the process, safe across `config:cache` and long-running
  workers. Do not keep request state in a contribution.
- Plugins are trusted in-process code; there is no sandbox.

## Stable keys, fail-closed

Data stores the **contribution key** and a `config_version`, never a class
name. When a plugin is removed, the rule that used it becomes `unsupported` and
fails closed: the promotion does not apply, a shipping rate is not offered, an
attribute reads as a raw read-only value. `commerz:doctor` and the
reconcile commands report it. Nothing is silently dropped or half-applied.

The closed set of kinds and their SPIs: [extension kinds](../reference/extension-kinds).
Writing one: [write a plugin](../how-to/write-a-plugin).
