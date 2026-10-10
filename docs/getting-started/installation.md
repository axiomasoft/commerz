# Installation

## Requirements

- PHP ^8.4 with `bcmath`, `intl`, `mbstring` and `pdo_pgsql`.
- Laravel ^13 (`illuminate/*` ^13).
- PostgreSQL ≥ 16. The pricing migration creates the `btree_gist` extension
  (`create extension if not exists btree_gist`); the migrating role needs
  `CREATE` on the database.

## Require the packages

A shop takes everything:

```bash
composer require axiomasoft/commerz-full
```

Or pick a subset. The core is always required, and feature packages require it
themselves:

```bash
composer require axiomasoft/commerz axiomasoft/commerz-pricing axiomasoft/commerz-sales
```

Service providers are discovered through `extra.laravel.providers`; there is
nothing to register by hand.

## Install

```bash
php artisan commerz:install
```

`commerz:install` publishes the core configuration (tag `commerz-config`) and
runs `migrate`. Use `--no-migrate` to only publish, and `--force` to overwrite
the published file or to migrate in production.

::: warning Choose the table prefix first
`commerz.table_prefix` (default `cmz_`) is fixed by the first migration. Its
fingerprint is stored in `cmz_schema_state`; changing it afterwards is a boot
error. Set it before running `commerz:install`.
:::

Each feature package publishes its own configuration file
(`commerz-inventory.php`, `commerz-pricing.php` and so on) under the tag
`commerz-<package>-config`, for example
`php artisan vendor:publish --tag=commerz-pricing-config`. The defaults work without publishing; see the
[configuration reference](../reference/configuration).

## Verify

```bash
php artisan commerz:doctor
```

The doctor checks the configuration, the database and the install fingerprint,
and the plugin registry. It exits non-zero when something is wrong. List the
registered plugins with `php artisan commerz:plugins`.

## Configure the host

The few things a host decides, all in `config/commerz.php`:

| Key | Meaning |
|---|---|
| `connection` | Database connection for every Commerz table (`null` = default). |
| `table_prefix` | Table prefix, 2–7 lowercase letters or digits and `_`. |
| `stock` | `inventory` (check sales against the inventory package) or `unlimited`. |
| `customers` | Class implementing `CustomerDirectory`, or `null` for guests only. |
| `plugins` | Host plugin classes, registered in order. |

With `stock = inventory` and no inventory package installed, the first use of a
stock port fails with `InvalidConfiguration`. A CRM without stock sets
`stock = unlimited`.

Continue with the [quick start](./quick-start-shop) or the
[CRM embedding](./crm-embedding).
