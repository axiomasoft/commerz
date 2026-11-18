#!/usr/bin/env bash
# Consumer install smoke: installs the 0.1.0 composer archives into a fresh Laravel 13 app, without the monorepo.
# Run under PHP 8.4: ./bin/php84 bash scripts/test-consumer-install.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
VERSION="0.1.0"
WORK="${ROOT}/var/consumer"
ARTIFACTS="${WORK}/artifacts"
APP_FULL="${WORK}/app-full"
APP_CRM="${WORK}/app-crm"
SMOKE_SRC="${ROOT}/tests/Consumer/smoke.php"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-54329}"
DB_USERNAME="${DB_USERNAME:-commerz}"
DB_PASSWORD="${DB_PASSWORD:-commerz}"
ADMIN_DB="${CONSUMER_ADMIN_DB:-commerz_test}"
CONSUMER_DB="commerz_consumer_test"

PACKAGES=(core catalog inventory pricing promotions shipping sales full testkit)
PACKMAN_PACKAGES=(packman packman-plugins packman-schema packman-panels packman-testkit)
PACKMAN_RUNTIME=(packman packman-plugins packman-schema packman-panels)
LOCKED_ZIPS=(commerz commerz-catalog commerz-inventory commerz-pricing commerz-promotions commerz-shipping commerz-sales)

log() { printf '[consumer] %s\n' "$*"; }
die() { log "ERROR: $*" >&2; exit 1; }

guard_work_dir() {
  [[ "$WORK" == "${ROOT}/var/consumer" ]] || die "work dir must be var/consumer"
  [[ "$WORK" != "$ROOT" && "$WORK" != "/" ]] || die "refusing to use the monorepo root as work dir"
}

php_version_ok() {
  php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);' \
    || die "PHP 8.4 required (run under ./bin/php84), got $(php -r 'echo PHP_VERSION;')"
}

admin_sql() {
  PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -d "$ADMIN_DB" -v ON_ERROR_STOP=1 -qtAc "$1"
}

cleanup() {
  local status=$?
  trap - EXIT
  admin_sql "DROP DATABASE IF EXISTS ${CONSUMER_DB} WITH (FORCE)" >/dev/null 2>&1 || true
  guard_work_dir
  rm -rf "$WORK"
  exit "$status"
}

package_name() {
  case "$1" in
    core) echo "axiomasoft-commerz" ;;
    *) echo "axiomasoft-commerz-$1" ;;
  esac
}

stage_archive() {
  local pkg="$1" staging source archive
  staging="$(mktemp -d "${WORK}/staging.XXXXXX")"
  source="${ROOT}/packages/${pkg}"
  archive="$(package_name "$pkg")"
  if [[ "$pkg" == packman* ]]; then
    source="${ROOT}/vendor/axiomasoft/${pkg}"
    archive="axiomasoft-${pkg}"
  fi
  rsync -aL --exclude '.git' --exclude 'vendor' --exclude 'node_modules' --exclude 'tests' --exclude 'phpunit*' "${source}/" "${staging}/"
  if [[ ! -f "${staging}/LICENSE" && "$pkg" == packman* ]]; then
    cp "$(dirname "$(dirname "$(readlink -f "$source")")")/LICENSE" "${staging}/LICENSE"
  fi
  php -r '
    $data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $data["version"] = $argv[2];
    file_put_contents($argv[1], json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
  ' "${staging}/composer.json" "$VERSION"
  (cd "$staging" && composer archive --format=zip --dir="$ARTIFACTS" --file="${archive}-${VERSION}" >/dev/null)
  rm -rf "$staging"
}

assert_archives() {
  php -r '
    foreach (glob($argv[1]."/*.zip") as $file) {
      $zip = new ZipArchive();
      $zip->open($file);
      for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match("#^(tests/|phpunit)#", $name) || $name === ".gitattributes") {
          fwrite(STDERR, basename($file).": {$name} must be export-ignored\n");
          exit(1);
        }
      }
      if ($zip->locateName("composer.json") === false || $zip->locateName("LICENSE") === false) {
        fwrite(STDERR, basename($file).": composer.json or LICENSE missing\n");
        exit(1);
      }
      // Every file of the source dirs a consumer needs must be in the archive (a package without migrations still passes install/doctor).
      preg_match("/^axiomasoft-commerz(?:-(.+?))?-[0-9.]+\.zip$/", basename($file), $m);
      $pkg = $m[1] ?? "core";
      $sourceRoot = $argv[2]."/packages/{$pkg}";
      if (preg_match("/^axiomasoft-(packman(?:-[a-z]+)?)-[0-9.]+\\.zip$/", basename($file), $m)) {
        $sourceRoot = $argv[2]."/vendor/axiomasoft/".$m[1];
      }
      foreach (["database/migrations", "config", "src"] as $dir) {
        foreach (glob($sourceRoot."/{$dir}/*.php") as $source) {
          if ($zip->locateName("{$dir}/".basename($source)) === false) {
            fwrite(STDERR, basename($file).": {$dir}/".basename($source)." missing from the archive\n");
            exit(1);
          }
        }
      }
    }
  ' "$ARTIFACTS" "$ROOT" || die "archive content check failed"
  [[ "$(ls "$ARTIFACTS"/*.zip | wc -l)" -eq "$((${#PACKAGES[@]} + ${#PACKMAN_PACKAGES[@]}))" ]] || die "unexpected archive count"
}

consumer_env() {
  export DB_CONNECTION=pgsql DB_HOST DB_PORT DB_DATABASE="$CONSUMER_DB" DB_USERNAME DB_PASSWORD
  export COMPOSER_HOME="${WORK}/composer-home" COMPOSER_CACHE_DIR="${WORK}/composer-cache"
  export COMPOSER_NO_INTERACTION=1
}

new_app() {
  local dir="$1" kv key
  composer create-project "laravel/laravel:^13.0" "$dir" --prefer-dist --no-interaction --quiet
  (
    cd "$dir"
    composer config repositories.commerz-artifacts artifact "$ARTIFACTS"
    composer config repositories.packagist.org --json '{"type":"composer","url":"https://repo.packagist.org","exclude":["axiomasoft/*"]}'
    sed -i "s/^DB_CONNECTION=.*/DB_CONNECTION=pgsql/" .env
    for kv in "DB_HOST=${DB_HOST}" "DB_PORT=${DB_PORT}" "DB_DATABASE=${CONSUMER_DB}" "DB_USERNAME=${DB_USERNAME}" "DB_PASSWORD=${DB_PASSWORD}"; do
      key="${kv%%=*}"
      if grep -q "^#\? *${key}=" .env; then sed -i "s|^#\? *${key}=.*|${kv}|" .env; else echo "$kv" >> .env; fi
    done
  )
}

assert_lock() {
  local dir="$1"; shift
  php -r '
    $lock = json_decode(file_get_contents($argv[1]."/composer.lock"), true, 512, JSON_THROW_ON_ERROR);
    $found = [];
    foreach ($lock["packages"] as $p) {
      if (str_starts_with($p["name"], "axiomasoft/")) {
        $found[$p["name"]] = $p;
      }
    }
    $want = array_slice($argv, 3);
    sort($want);
    $have = array_keys($found);
    sort($have);
    if ($have !== $want) {
      fwrite(STDERR, "lock: ".implode(",", $have)." != ".implode(",", $want)."\n");
      exit(1);
    }
    foreach ($found as $name => $p) {
      if ($p["version"] !== $argv[2]) { fwrite(STDERR, "lock: {$name} is {$p["version"]}\n"); exit(1); }
      $isMeta = ($p["type"] ?? "") === "metapackage";
      if (! $isMeta && ($p["dist"]["type"] ?? "") !== "zip") { fwrite(STDERR, "lock: {$name} is not a zip dist\n"); exit(1); }
      if (! $isMeta && ! str_contains($p["dist"]["url"], "var/consumer/artifacts")) { fwrite(STDERR, "lock: {$name} not from the artifact repo\n"); exit(1); }
    }
  ' "$dir" "$VERSION" "$@" || die "composer.lock check failed ($dir)"
}

run_profile() {
  local profile="$1" dir="$2"; shift 2
  log "profile ${profile}: require $*"
  new_app "$dir"
  (cd "$dir" && composer require "$@" --with-all-dependencies --no-interaction)
  if [[ "$profile" == "full" ]]; then
    assert_lock "$dir" axiomasoft/commerz-full "${LOCKED_ZIPS[@]/#/axiomasoft/}" "${PACKMAN_RUNTIME[@]/#/axiomasoft/}"
  else
    assert_lock "$dir" axiomasoft/commerz axiomasoft/commerz-sales "${PACKMAN_RUNTIME[@]/#/axiomasoft/}"
  fi
  (
    cd "$dir"
    composer install --no-interaction --prefer-dist
    php artisan package:discover --ansi >/dev/null
    php artisan commerz:install --no-interaction
    if [[ "$profile" == "crm" ]]; then
      sed -i "s/'stock' => StockMode::Inventory->value/'stock' => 'unlimited'/" config/commerz.php
      grep -q "'stock' => 'unlimited'" config/commerz.php || die "could not switch commerz.stock to unlimited"
    fi
    php artisan packman:doctor
    php artisan packman:plugins --json > plugins.json
    php -r '
      $ids = array_map(fn ($p) => $p["id"], json_decode(file_get_contents("plugins.json"), true, 512, JSON_THROW_ON_ERROR));
      sort($ids);
      echo implode(",", $ids), "\n";
    '
    cp "$SMOKE_SRC" ./smoke.php
    CONSUMER_PROFILE="$profile" php smoke.php
  )
}

main() {
  local cmd
  for cmd in php composer rsync psql curl pg_isready; do command -v "$cmd" >/dev/null || die "missing command: $cmd"; done
  php_version_ok
  guard_work_dir
  curl -fsS -m 15 -o /dev/null https://repo.packagist.org/packages.json \
    || die "no network access to repo.packagist.org: the consumer install needs Laravel from Packagist"
  pg_isready -h "$DB_HOST" -p "$DB_PORT" >/dev/null || die "PostgreSQL is not accepting connections on ${DB_HOST}:${DB_PORT}"

  rm -rf "$WORK"
  mkdir -p "$ARTIFACTS"
  trap cleanup EXIT

  consumer_env
  admin_sql "DROP DATABASE IF EXISTS ${CONSUMER_DB} WITH (FORCE)"
  admin_sql "CREATE DATABASE ${CONSUMER_DB}"

  for pkg in "${PACKAGES[@]}" "${PACKMAN_PACKAGES[@]}"; do stage_archive "$pkg"; done
  assert_archives

  run_profile full "$APP_FULL" "axiomasoft/commerz-full:${VERSION}"
  admin_sql "DROP DATABASE ${CONSUMER_DB} WITH (FORCE)"
  admin_sql "CREATE DATABASE ${CONSUMER_DB}"
  run_profile crm "$APP_CRM" "axiomasoft/commerz:${VERSION}" "axiomasoft/commerz-sales:${VERSION}"

  log "ok: ${VERSION} installs and boots from archives (full and CRM sets)"
}

main "$@"
