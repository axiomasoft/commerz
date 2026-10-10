# Install and doctor

## Install

```bash
composer require axiomasoft/commerz-full        # or the packages you need
php artisan commerz:install
```

`commerz:install` publishes `config/commerz.php` and runs `migrate`.

| Option | Effect |
|---|---|
| `--no-migrate` | only publish the config; run `php artisan migrate` yourself |
| `--force` | overwrite the published config; allow `migrate` in production |

Migrations of every installed package are loaded by their service providers and
create tables with the configured prefix. They run on the Commerz connection
(`commerz.connection`). Plan the prefix and the connection **before** the first
migration: the prefix is fingerprinted into `cmz_schema_state` and cannot be
changed later.

Production rollout: run `php artisan migrate --force` as part of the deploy, before
workers restart. Migrations are transactional DDL on PostgreSQL.

## Doctor

```bash
php artisan commerz:doctor
```

Three checks, each printed `OK` or `FAIL`; the exit code is non-zero if any
fails:

| Check | Fails when |
|---|---|
| Configuration | a config value is invalid (`InvalidConfiguration`) |
| Database and fingerprint | the `schema_state` table is missing (not installed), the fingerprint is absent (the schema was not made by `commerz:install`), or it differs from the installed version (run `migrate`) |
| Plugins | the plugin registry does not compile: a duplicate id or key, an unknown kind, a cycle, an unknown required plugin |

Run it in CI after `migrate`, and after every deploy. It is safe: it only reads.

## Plugins

```bash
php artisan commerz:plugins          # table of plugins in load order, with their contributions
php artisan commerz:plugins --json   # machine-readable
```

Use it to confirm which plugins and contribution keys a host actually loaded.
A rule whose plugin is missing shows up as `unsupported` in the
[reconcile](./reconcile) reports.

## Uninstalling

There is no uninstall command. Remove a package only after its data is no
longer needed: its tables stay until you roll back its migrations, and rules
that used its plugins become `unsupported`, failing closed.
