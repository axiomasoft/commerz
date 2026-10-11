The consumer smoke does not prove that feature-package migrations are in the archive or applied. A green `commerz:install` and `commerz:doctor` only establish the core `schema_state` fingerprint. The rest of D36's install proof holds.

## Findings

**1. Medium** — `scripts/test-consumer-install.sh:70` and `packages/core/src/Internal/Console/DoctorCommand.php:45`

`assert_archives` accepts any zip that contains `composer.json` and `LICENSE` and does not contain root `tests/` or `phpunit*` entries. `commerz:doctor` then only checks that `{prefix}schema_state` exists and that its fingerprint equals `prefix` + `id_strategy` + `schema_version` (`packages/core/src/Schema/Internal/SchemaFingerprint.php:24`). `tests/Consumer/smoke.php` never opens the database. Laravel treats a missing migrations directory as an empty list (`vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:581` globs `*_*.php`), so `php artisan migrate` still returns 0. Dropping `database/migrations` from catalog, inventory, pricing, promotions, shipping, or sales still passes both profiles. Core is covered, because a missing `schema_state` fails the doctor.

REPRODUCTION: a zip with only `composer.json`, `LICENSE`, and `src/CatalogServiceProvider.php` passes the script's archive check, and a missing migrations path globs to nothing.

```text
fake-no-migrations.zip entries=composer.json,LICENSE,src/CatalogServiceProvider.php
migrations_in_zip=no
assert_archives_style_exit=0
glob_of_missing_dir=[] count=0
```

The same archive built from this tree's `packages/catalog` does contain 10 files under `database/migrations/`. The checker never requires that.

**2. Low** — `packages/core/composer.json:12` and `packages/core/src/Internal/Console/InstallCommand.php:9`

Every package command extends `Illuminate\Console\Command`, and the smoke runs `commerz:install`, `commerz:doctor`, and `commerz:plugins`. No package `composer.json` requires `illuminate/console`. `illuminate/support`, `illuminate/database`, and `illuminate/contracts` do not require it either. `laravel/framework` replaces it (`vendor/laravel/framework/composer.json:109`), and `laravel/laravel` always installs the framework, so the host app hides the gap. The same pattern is in the inventory, promotions, and sales command classes.

REPRODUCTION: `packages/core/composer.json` `require` is `php`, `illuminate/contracts`, `illuminate/support`, `illuminate/database`, and `brick/math`. `InstallCommand` is `final class InstallCommand extends Command`.

## What held up

A symlink or a path install cannot pass. `tests/Consumer/smoke.php:77` rejects a symlinked `vendor/axiomasoft/<package>`, and the provider file's `realpath` must stay inside that directory. `assert_lock` (`scripts/test-consumer-install.sh:130`) requires every non-metapackage `axiomasoft/*` entry to be version `0.1.0`, `dist.type` `zip`, and a URL containing `var/consumer/artifacts`. On PHP 8.4, `php -r` puts the directory in `$argv[1]`, the version in `$argv[2]`, and the package names at `$argv[3+]`, which is what `array_slice($argv, 3)` expects.

Packagist cannot supply those packages. `composer config repositories.packagist.org` writes a single `packagist.org` repository with `exclude: ["axiomasoft/*"]`, which replaces the default Packagist. Composer 2.8 disables the built-in `packagist.org` entry when that URL is set. The lock URL check still rejects a Packagist dist for every code package. `commerz-full` skips the dist check because it is a metapackage; it has no code, and the seven code packages stay on the zip check.

Tests in the archive fail the gate. Composer 2.8.2 stores entries at the zip root, so `^(tests/|phpunit)` matches. An archive of this tree's catalog, testkit, and full packages had zero such entries. The catalog zip still contains `config/`, 10 migrations, `resources/contracts/php-api.json`, `src/`, `LICENSE`, and `README.md`. All nine `.gitattributes` files are only `/tests`, `/phpunit.xml*`, and `/.gitattributes`. Testkit's contract tests and fakes live under `src/` and are in the zip.

The script stays inside `var/consumer` for its work directory. `ROOT` is `pwd -P`, the trap clears itself before `exit`, `cd` happens in subshells, and `DROP`/`CREATE` target the literal `commerz_consumer_test`. `DB_*` is exported before Laravel boots, so a missed `.env` edit still points at that database. The CRM `sed` is checked with `grep`, and the published line is exactly `'stock' => StockMode::Inventory->value` (`packages/core/config/commerz.php:22`). `commerz:install` does not cache config, and `mergeConfigFrom` does not overwrite the published `stock` key. No `--ignore-platform-reqs`, no git tag, and no network skip: a failed `curl` to `https://repo.packagist.org/packages.json` exits 1.

`CHANGELOG.md` matches the code I checked: plugin ids `commerz.core` through `commerz.shipping`, the 14 kind names, the nine Artisan commands, the three promotion stages, `btree_gist` in the price migration, port fakes for every core port, and `commerz-full` requiring core plus the six feature packages. `ManualGateway` and `ManualCarrier` are in-process offline implementations. Packagist returns 404 for `axiomasoft/commerz` and `axiomasoft/commerz-full`. `docs/reference/api/index.md` is present. `commerz-full` has no `php-api.json`; the manifest generator only reads packages that have one.

`.github/workflows/ci.yml` does not run the script. P8.8 only creates the local tag. The script's defaults match the tests job's Postgres service (`127.0.0.1:54329`, user and password `commerz`, admin database `commerz_test`).

Verdict: fail

Residual risk: `axiomasoft/commerz-testkit` is archived and never installed, so a broken testkit dependency still passes. `CONSUMER_DB` is fixed at `commerz_consumer_test`, so two overlapping runs would drop each other's database. The doctor fingerprint does not name feature tables, which is the gap in finding 1. The shipped config default remains `inventory`, and unlimited stock is the fallback only after `commerz.stock` is set to `unlimited`.