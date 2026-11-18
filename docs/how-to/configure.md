# Configure Commerz

Configuration is deliberately small and typed. Data (assortment, prices,
promotions, zones) is created through the API; configuration only picks
policies. The full key list is the [configuration reference](../reference/configuration).

## Where it lives

| File | Package | Publish tag |
|---|---|---|
| `config/commerz.php` | core | `commerz-config` (done by `commerz:install`) |
| `config/commerz-catalog.php` | catalog | `commerz-catalog-config` |
| `config/commerz-inventory.php` | inventory | `commerz-inventory-config` |
| `config/commerz-pricing.php` | pricing | `commerz-pricing-config` |
| `config/commerz-promotions.php` | promotions | `commerz-promotions-config` |
| `config/commerz-shipping.php` | shipping | `commerz-shipping-config` |
| `config/commerz-sales.php` | sales | `commerz-sales-config` |

Every file works unpublished: the defaults are merged. Publish only what you
change. Invalid values fail at boot with `InvalidConfiguration`; run
`php artisan packman:doctor` after changing them.

## Common decisions

**Use a dedicated connection** — point every Commerz table at one connection
(a separate database per tenant is the way to isolate tenants):

```php
'connection' => 'commerce',
```

**Skip stock tracking** (a CRM, digital goods):

```php
'stock' => 'unlimited',
```

**Name customers from your own system:**

```php
'customers' => App\Commerce\AccountDirectory::class,   // implements CustomerDirectory
```

**Register host plugins:**

```php
'plugins' => [App\Commerce\AcmePlugin::class],
```

**Give promotion usage back on a full refund** (the default restores only on
cancellation and expiry):

```php
// config/commerz-promotions.php
'restore_on' => ['cancelled', 'expired', 'refunded'],
```

**Tax a CRM without a delivery address** in its home zone:

```php
// config/commerz-pricing.php
'tax' => ['default_zone' => 'ru'],
```

**Choose how stock is allocated across locations:**

```php
// config/commerz-inventory.php
'allocation_strategy' => 'single_location',   // or 'priority', or a plugin key
```

## Things you cannot change later

`commerz.table_prefix` is checked against the fingerprint stored by the first
migration; changing it afterwards is a boot error. Choose it before
`commerz:install`.

## Config caching

Configuration and the plugin registry survive `config:cache`. Do not put
closures or objects in configuration: plugins are class names, and rules are
data with stable keys.
