# Upgrades

## Versioning

Commerz follows SemVer; the [SemVer policy](../reference/semver-policy) lists
what is public. Packages are versioned together: all Commerz packages in one
installation share the same `0.1` line, and inter-package requirements are
`^0.1`. Upgrade them together:

```bash
composer update 'axiomasoft/commerz*'
php artisan migrate
php artisan commerz:doctor
```

`commerz:doctor` compares the stored schema fingerprint with the installed
version and tells you to run `migrate` when they differ.

## Migrations

- Migrations are additive and transactional on PostgreSQL. A release that
  changes a table adds a new migration; it does not rewrite an old one.
- Migration file names, table names, columns and constraints are a SemVer
  surface. A column or constraint is not removed or renamed in a minor release.
- Third-party plugin tables use `cmz_x_<vendor>_*`, so they never collide.
- Roll back only with a backup: some migrations narrow data types and refuse to
  round or overflow values.

## Stored rules and plugins

Rules (promotion conditions and effects, shipping rates) are stored by key and
`config_version`. When a plugin raises its version, its `upgrade()` converts
the stored configuration on load. When a plugin is removed or cannot read a
version, the rule becomes `unsupported` and fails closed.

After an upgrade, run `commerz:plugins`, `commerz:promotions:reconcile` and, for
shipping, check the methods in the admin for unsupported rates.

## Pre-1.0

While the version is `0.x`, minor releases may still change public API. Pin
`^0.1` and read the release notes before moving to `0.2`. Types marked `#[Api]`
or `#[Spi]` with a `since` version are the surface that will be covered by the
policy from 1.0.
