The correction closes both round-1 findings. No new finding.

**Finding 1.** `assert_archives` maps the archive file name to the source package and requires every top-level `database/migrations`, `config`, and `src` `*.php` to be present at that zip path (`scripts/test-consumer-install.sh:87-96`). On PHP 8.4 the optional capture is absent for `axiomasoft-commerz-0.1.0.zip`, so `$m[1] ?? "core"` is `core`. `full` and `testkit` map to those directories. Composer 2.8.2 stores entries at the zip root (`database/migrations/…php`), and `locateName` is compared with `=== false` (`LICENSE` is index 0). `full` and `testkit` have no `database/migrations`; the glob is empty there. The seven installed packages have only flat migration files, and a catalog zip missing one of them fails.

```text
axiomasoft-commerz-0.1.0.zip => pkg="core"
  dir=database/migrations glob_count=5
  dir=config glob_count=1
  dir=src glob_count=3
axiomasoft-commerz-full-0.1.0.zip => pkg="full"
  dir=database/migrations glob_count=0
  dir=config glob_count=0
  dir=src glob_count=0
axiomasoft-commerz-testkit-0.1.0.zip => pkg="testkit"
  dir=database/migrations glob_count=0
  dir=src glob_count=4
assert_ok
real_exit=0

dropping database/migrations/2026_10_09_100001_create_commerz_attribute_tables.php
pkg=catalog
  database/migrations sources=10
axiomasoft-commerz-catalog-0.1.0.zip: database/migrations/2026_10_09_100001_create_commerz_attribute_tables.php missing from the archive
stripped_exit=1
hollow_exit=1
```

`tests/Consumer/smoke.php:90-102` fails when `glob(…/database/migrations/*.php)` is empty. It looks up `basename($file, '.php')` in `array_flip` of `DB::table('migrations')->pluck('migration')`. That column is the string Laravel stores (`Migrator.php:608-610`, `DatabaseMigrationRepository.php:121`). The framework default is `database.migrations.table=migrations` (`vendor/laravel/framework/config/database.php:145-146`). Commerz does not call `setTablePrefix`; `cmz_` comes from `TableNames`. All 32 migration files are unique, match `*_*.php`, and sit directly in `database/migrations`. Each installed provider loads that same directory (`loadMigrationsFrom`).

```text
unique=32 dups=0
catalog top=10 all=10 sub=0 migrator_glob=10
core top=5 all=5 sub=0 migrator_glob=5
inventory top=4 … pricing top=2 promotions top=3 sales top=5 shipping top=3
full migrations_dir=no
testkit migrations_dir=no
catalog_count=10
isset_real=yes
isset_missing=no
key_type=string sample=2026_10_09_100001_create_commerz_attribute_tables
files=32 basename_vs_migrator_mismatches=0
full_glob_count=0 ships_no_migrations_fails=yes
core_glob_count=5 empty_fails=no
```

**Finding 2.** Shipped `use Illuminate\Console\` is only in core (5 files), inventory (2), promotions (1), and sales (1), and those four `composer.json` files require `"illuminate/console": "^13.0"`. Catalog, pricing, shipping, full, and testkit have no console classes. The other shipped heads are `Contracts`, `Support`, `Database`, `Container` (required by `illuminate/database`, `vendor/laravel/framework/src/Illuminate/Database/composer.json:28`), and testkit's `Foundation` (`orchestra/testbench` requires `laravel/framework`, `vendor/orchestra/testbench/composer.json:27`). Locked `laravel/framework` is `v13.35.0` and replaces `illuminate/console` at `self.version`.

```text
core require_console=yes command_files=5
catalog require_console=no command_files=0
inventory require_console=yes command_files=2
pricing require_console=no command_files=0
promotions require_console=yes command_files=1
shipping require_console=no command_files=0
sales require_console=yes command_files=1
full require_console=no command_files=0
testkit require_console=no command_files=0
```

The new `.gitattributes` files only export-ignore `/tests`, `/phpunit.xml*`, and `/.gitattributes`. Real zips contain the migration files and zero `tests/` entries. The consumer script itself was not run; it writes under `var/`.

Verdict: pass

Residual risk: the archive glob is top-level only, so removing `src/Fakes/FakeCustomers.php` from the testkit zip still exits 0 (`nested_omission_still_passes`, `nested_exit=0`). Nested `src/` and `resources/` can be absent from a zip while this check stays green; smoke only boots providers and the profile root classes. `full` and `testkit` ship no migrations, and neither profile installs testkit. `CONSUMER_DB` remains the fixed name `commerz_consumer_test`.