# A custom sellable provider

Sell something that is not a catalog variant — a CRM service, a subscription, a
ticket — by serving it as a **sellable**. The worked example is the CRM in
[embedding in a CRM](../getting-started/crm-embedding); this page is the checklist.

## The contract

```php
interface SellableProvider
{
    public function key(): string;                              // the SellableRef type you serve
    public function resolve(array $refs, CommerceContext $context): array;   // array<string, SellableSnapshot>
}
```

- `key()` is the ref type: refs look like `crm.service:audit`. Use a
  vendor-prefixed type so it never collides with `variant`.
- `resolve()` receives only refs of your type, without duplicates, and returns
  snapshots **keyed by `(string) $ref`**. Leave out refs you do not know.
- **Reads only**: no writes, no network inside a transaction. One query for
  the whole batch beats one per ref.

## The snapshot

| Field | Why it matters |
|---|---|
| `kind` | `Physical`, `Digital` or `Service` |
| `requiresShipping` | `false` means no delivery method is demanded at checkout |
| `purchasable`, `unavailableReason` | return unsellable items with a reason rather than dropping them |
| `basePrice` | used when pricing has no list price (`fallback_to_sellable`) and by the core default resolver |
| `taxClass` | the pricing tax class; `null` uses `commerz-pricing.tax.default_class` |
| `weightGrams` | read by shipping tariffs |

Decide `purchasable` from `$context` — channel, currency and `at` — so an item
can be unavailable in one channel and not in another.

## Register it

```php
public function register(PluginRegistrar $registrar): void
{
    $registrar->sellableProvider(CrmServices::class);
}
```

Add the plugin to `commerz.plugins`. Add the refs to carts like any other:

```php
Sales::carts()->addLine(new AddCartLine($cart, SellableRef::of('crm.service', 'audit'), 1));
```

## Stock and prices for custom sellables

- **Stock.** With `commerz.stock = unlimited` nothing is reserved. With
  `inventory`, link the ref to a stock item
  (`Inventory::items()->link(new LinkSellable($ref, $item, 1))`); a ref without
  a link is `unavailable` unless `commerz-inventory.unlinked_sellables` is
  `unlimited`.
- **Prices.** Put prices in a price list (`Pricing::prices()->set(...)`) for the
  sellable ref, or rely on `basePrice`.

## Snapshot semantics

The order stores the snapshot it was quoted with. Renaming or deleting the
underlying entity never changes a placed order.
