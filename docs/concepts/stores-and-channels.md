# Stores and channels

| Concept | Meaning | Lives in |
|---|---|---|
| Tenant | the data-isolation boundary | **no column**: a separate connection or database, chosen by the host |
| Store | a business unit: seller, default currency, order numbering | `cmz_stores` (core) |
| Channel | a point of sale: website, POS, marketplace, B2B portal, CRM manager | `cmz_channels` (core) |
| Customer | the host's buyer | `CustomerRef` + the `CustomerDirectory` port |
| Actor | who executes a command: operator, customer, system | `ActorRef` |

Several stores and channels in one database are supported natively. A shared
schema for many tenants is not; give each tenant a connection and point
`commerz.connection` at it.

```php
$store = Commerz::stores()->create(new CreateStore('shop', 'Shop', Currency::of('RUB'), 'WEB-'));
$channel = Commerz::channels()->create(
    new CreateChannel($store->id, 'web', 'Web', [Currency::of('RUB')], ['ru'], pricesIncludeTax: true),
);
```

A channel declares its currencies and locales, and whether its prices include
tax. Prices, promotions, delivery methods, stock sources and catalog listings
are assigned **to channels**; a store only supplies the numbering prefix and the
default currency.

## The commerce context

Every pricing and sales operation takes an explicit `CommerceContext`:

| Field | |
|---|---|
| `channel` | `ChannelId` the sale happens in |
| `currency` | `Currency` of the sale |
| `locale` | locale tag for texts |
| `customer`, `customerGroups` | optional `CustomerRef` and the groups from the directory |
| `at` | the moment the sale is evaluated at |

```php
$context = CommerceContext::for($channel, Currency::of('RUB'), 'ru');
```

The context is never held in a singleton or global scope: it is a value you
pass. A `ContextResolver` is an SPI that helps a host build it from a request.
A cart fixes its channel and currency at creation and they never change. All
time-dependent decisions — price windows, promotion periods, tax rates —
use `context.at`, never the clock, so a quote is reproducible.

Authorization stays with the host's transport: Commerz checks invariants and
context, not permissions.
