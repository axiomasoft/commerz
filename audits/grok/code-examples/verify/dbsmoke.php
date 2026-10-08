<?php
// Интеграционная проверка на PostgreSQL: миграции, резерв, купоны, конкуренция (8 процессов).
// Работает в отдельной схеме smoke и удаляет её в конце. Пример: PGHOST=/tmp PGPORT=55499 PGUSER=grok composer db
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Commerz\Config\Data\CommerzConfig;
use Commerz\Config\Enums\AvailabilitySource;
use Commerz\Money\Enums\RoundingMode;
use Commerz\Schema\Contracts\TableNames;
use Commerz\Inventory\Internal\Handlers\ReserveStockHandler;
use Commerz\Inventory\Data\{ReserveStock, ReservationLine};
use Commerz\Inventory\Enums\DemandType;
use Commerz\Inventory\Exceptions\{InsufficientStock, ReservationConflict};
use Commerz\Coupons\Internal\Handlers\RedeemCouponHandler;
use Commerz\Promotions\Data\{ActivationCommit, ActivationRevert};
use Commerz\Promotions\Exceptions\PromotionNoLongerAvailable;
use Commerz\Money\Data\{Money, Currency};
use Illuminate\Support\Str;

function connect(): Capsule {
    $c = Container::getInstance() ?? new Container();
    Container::setInstance($c);
    $capsule = new Capsule($c);
    $capsule->addConnection(['driver' => 'pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1', 'port' => (int) (getenv('PGPORT') ?: 5432), 'database' => getenv('PGDATABASE') ?: 'cmz', 'username' => getenv('PGUSER') ?: 'postgres', 'password' => getenv('PGPASSWORD') ?: '', 'search_path' => 'smoke', 'charset' => 'utf8']);
    $capsule->setAsGlobal();
    $c->instance('db', $capsule->getDatabaseManager());
    $c->bind('db.schema', fn() => $capsule->getConnection()->getSchemaBuilder());
    Facade::setFacadeApplication($c);
    $c->instance(CommerzConfig::class, new CommerzConfig(null, 'cmz_', RoundingMode::HalfUp, AvailabilitySource::Inventory, []));
    $c->instance(TableNames::class, new class implements TableNames { public function of(string $logical): string { return 'cmz_'.$logical; } });
    return $capsule;
}
function check(bool $ok, string $what): void { echo ($ok ? 'PASS ' : 'FAIL ').$what.PHP_EOL; if (!$ok) exit(1); }

$mode = $argv[1] ?? 'main';
$capsule = connect();
$db = $capsule->getConnection();
$tables = Container::getInstance()->make(TableNames::class);

if ($mode === 'worker') {   // конкурентный резерв: по 1 шт., уникальный заказ
    [$level, $n, $id] = [$argv[2], (int) $argv[3], $argv[4]];
    $h = new ReserveStockHandler($db, $tables); $ok = 0;
    for ($i = 0; $i < $n; $i++) {
        try { $db->transaction(fn() => $h->reserve(new ReserveStock(DemandType::Order, "w$id-$i", [new ReservationLine($level, 'l1', 1)]))); $ok++; }
        catch (InsufficientStock) {}
    }
    echo $ok; exit(0);
}
if ($mode === 'coupon-worker') {
    [$promo, $n, $id] = [$argv[2], (int) $argv[3], $argv[4]];
    $h = new RedeemCouponHandler($db, $tables); $ok = 0;
    for ($i = 0; $i < $n; $i++) {
        try { $db->transaction(fn() => $h->handle(new ActivationCommit((string) Str::uuid7(), $promo, 'LIMIT10', "cust-$id-$i", new Money(100, new Currency('RUB', 2))))); $ok++; }
        catch (PromotionNoLongerAvailable) {}
    }
    echo $ok; exit(0);
}

$db->statement('DROP SCHEMA IF EXISTS smoke CASCADE'); $db->statement('CREATE SCHEMA smoke');
$db->statement('CREATE TABLE cmz_stock_items (id uuid PRIMARY KEY)'); $db->statement('CREATE TABLE cmz_stock_locations (id uuid PRIMARY KEY)');
$ex = __DIR__.'/../migrations/';
foreach (['2026_10_08_000300_create_cmz_stock_tables.php', '2026_10_08_000400_create_cmz_promotion_tables.php', '2026_10_08_000500_create_cmz_coupon_tables.php'] as $m) { (require $ex.$m)->up(); }
$cons = $db->select("SELECT conname FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace WHERE n.nspname='smoke' ORDER BY 1");
check(count(array_filter($cons, fn($r) => str_ends_with($r->conname, '_pkey') && !str_contains($r->conname, 'stock_items') && !str_contains($r->conname, 'stock_locations'))) === 0 && count($cons) > 20, 'migrations ran; constraints: '.count($cons));
echo '  e.g. '.implode(', ', array_slice(array_map(fn($r) => $r->conname, $cons), 0, 8)).PHP_EOL;

[$item, $loc, $level] = [(string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];
$db->insert('INSERT INTO cmz_stock_items VALUES (?)', [$item]); $db->insert('INSERT INTO cmz_stock_locations VALUES (?)', [$loc]);
$db->insert('INSERT INTO cmz_stock_levels (id, stock_item_id, location_id, on_hand, safety_stock) VALUES (?,?,?,?,?)', [$level, $item, $loc, 10, 2]);
$h = new ReserveStockHandler($db, $tables);

try { $h->reserve(new ReserveStock(DemandType::Order, 'o0', [new ReservationLine($level, 'l1', 1)])); check(false, 'tx guard'); }
catch (LogicException) { check(true, 'reserve outside transaction is refused'); }

$r = $db->transaction(fn() => $h->reserve(new ReserveStock(DemandType::Order, 'o1', [new ReservationLine($level, 'l1', 5)])));
$r2 = $db->transaction(fn() => $h->reserve(new ReserveStock(DemandType::Order, 'o1', [new ReservationLine($level, 'l1', 5)])));
check(!$r->lines[0]->replayed && $r2->lines[0]->replayed && (int) $db->scalar('SELECT reserved FROM cmz_stock_levels') === 5, 'replay of same command does not double reserve');
try { $db->transaction(fn() => $h->reserve(new ReserveStock(DemandType::Order, 'o1', [new ReservationLine($level, 'l1', 3)]))); check(false, 'conflict'); }
catch (ReservationConflict) { check(true, 'same key, other quantity → ReservationConflict'); }
try { $db->transaction(fn() => $h->reserve(new ReserveStock(DemandType::Order, 'o2', [new ReservationLine($level, 'l1', 4)]))); check(false, 'insufficient'); }
catch (InsufficientStock $e) { check($e->available === 3 && (int) $db->scalar('SELECT reserved FROM cmz_stock_levels') === 5 && (int) $db->scalar("SELECT count(*) FROM cmz_stock_reservations WHERE demand_id='o2'") === 0, 'insufficient (10-5-2=3 < 4): rolled back, available reported'); }

// lazy expiry: бронь корзины на 3 с TTL, «состарим» её, новый резерв освобождает
$db->transaction(fn() => $h->reserve(new ReserveStock(DemandType::Cart, 'c1', [new ReservationLine($level, 'l1', 3)], ttlMinutes: 15)));
$db->update("UPDATE cmz_stock_reservations SET expires_at = now() - interval '1 minute' WHERE demand_id = 'c1'");
$db->transaction(fn() => $h->reserve(new ReserveStock(DemandType::Order, 'o3', [new ReservationLine($level, 'l1', 3)])));
check((int) $db->scalar('SELECT reserved FROM cmz_stock_levels') === 8 && $db->scalar("SELECT state FROM cmz_stock_reservations WHERE demand_id='c1'") === 'expired', 'expired cart hold released lazily inside the reserve transaction');

// revive: тот же ключ корзины после истечения снова резервирует (сначала приход +2: 12 − 8 − 2 = 2)
$db->update('UPDATE cmz_stock_levels SET on_hand = on_hand + 2');
$db->transaction(fn() => $h->reserve(new ReserveStock(DemandType::Cart, 'c1', [new ReservationLine($level, 'l1', 0 + 2)], 15)));
check((int) $db->scalar('SELECT reserved FROM cmz_stock_levels') === 10 && $db->scalar("SELECT state FROM cmz_stock_reservations WHERE demand_id='c1'") === 'active', 'expired reservation with same key is revived, not duplicated');

// конкуренция: 8 процессов × 20 попыток по 1 шт. на уровне с доступными 48
$hot = (string) Str::uuid7(); $item2 = (string) Str::uuid7();
$db->insert('INSERT INTO cmz_stock_items VALUES (?)', [$item2]);
$db->insert('INSERT INTO cmz_stock_levels (id, stock_item_id, location_id, on_hand, safety_stock) VALUES (?,?,?,?,?)', [$hot, $item2, $loc, 50, 2]);
$procs = [];
for ($w = 0; $w < 8; $w++) { $procs[] = popen(PHP_BINARY." ".__FILE__." worker $hot 20 $w", 'r'); }
$total = 0; foreach ($procs as $p) { $total += (int) stream_get_contents($p); pclose($p); }
$row = $db->selectOne('SELECT reserved, (SELECT count(*) FROM cmz_stock_reservations WHERE stock_level_id = l.id AND state = \'active\') AS active FROM cmz_stock_levels l WHERE id = ?', [$hot]);
check($total === 48 && (int) $row->reserved === 48 && (int) $row->active === 48, "concurrency: 160 attempts → {$total} reserved, counter {$row->reserved}, rows {$row->active} (no oversell, no lost update)");

// купон: лимит 10, 8 процессов × 5 попыток
$promo = (string) Str::uuid7();
$db->insert("INSERT INTO cmz_promotions (id, store_id, code, name, stage, stacking, activator, status) VALUES (?, ?, 'P', '{}', 'cart', 'stackable', 'coupon', 'active')", [$promo, (string) Str::uuid7()]);
$db->insert("INSERT INTO cmz_coupons (id, promotion_id, code, usage_limit, status) VALUES (?, ?, 'LIMIT10', 10, 'active')", [(string) Str::uuid7(), $promo]);
$procs = [];
for ($w = 0; $w < 8; $w++) { $procs[] = popen(PHP_BINARY." ".__FILE__." coupon-worker $promo 5 $w", 'r'); }
$total = 0; foreach ($procs as $p) { $total += (int) stream_get_contents($p); pclose($p); }
check($total === 10 && (int) $db->scalar("SELECT usage_count FROM cmz_coupons") === 10 && (int) $db->scalar("SELECT count(*) FROM cmz_coupon_redemptions") === 10, "coupon limit 10 under 40 concurrent attempts → {$total} redemptions");

// идемпотентность и откат погашения
$order = (string) Str::uuid7(); $rh = new RedeemCouponHandler($db, $tables);
$db->insert("INSERT INTO cmz_coupons (id, promotion_id, code, usage_limit, per_customer_limit, status) VALUES (?, ?, 'ONCE', 5, 1, 'active')", [(string) Str::uuid7(), $promo]);
$commit = new ActivationCommit($order, $promo, 'ONCE', 'cust-x', new Money(100, new Currency('RUB', 2)));
$db->transaction(fn() => $rh->handle($commit)); $db->transaction(fn() => $rh->handle($commit));
check((int) $db->scalar("SELECT usage_count FROM cmz_coupons WHERE code='ONCE'") === 1, 'repeated commit for same order is idempotent');
try { $db->transaction(fn() => $rh->handle(new ActivationCommit((string) Str::uuid7(), $promo, 'ONCE', 'cust-x', new Money(100, new Currency('RUB', 2))))); check(false, 'per customer'); }
catch (PromotionNoLongerAvailable $e) { check(str_contains($e->getMessage(), 'customer_limit'), 'per-customer limit enforced'); }
$rh->revert(new ActivationRevert($order, $promo, 'ONCE')); $rh->revert(new ActivationRevert($order, $promo, 'ONCE'));
check((int) $db->scalar("SELECT usage_count FROM cmz_coupons WHERE code='ONCE'") === 0, 'revert on cancel is idempotent');
$db->statement('DROP SCHEMA smoke CASCADE');
echo "ALL DB PASS\n";
