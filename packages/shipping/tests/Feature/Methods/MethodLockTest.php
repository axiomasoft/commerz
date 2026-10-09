<?php

declare(strict_types=1);

use Commerz\Errors\CommerzException;
use Commerz\Shipping\Methods\Data\MethodId;
use Commerz\Shipping\Methods\Data\SetMethodRates;
use Commerz\Shipping\Zones\Contracts\Zones;
use Commerz\Shipping\Zones\Data\ArchiveZone;
use Commerz\Shipping\Zones\Data\ZoneId;
use Commerz\Testkit\Concurrency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/../Zones/Helpers.php';

// Committed data across processes: no transactional isolation; the tables are emptied after each test. Lock timeouts
// stay at the default (3 s): a deadlock surfaces as SQLSTATE 40P01 and fails the worker.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    $this->artisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_shipping_rates, cmz_shipping_method_channels, cmz_shipping_methods, cmz_shipping_zone_members,'
        .' cmz_shipping_zones, cmz_channels, cmz_stores, cmz_outbox_messages, cmz_operation_receipts cascade');
});

it('lets archiveZone wait for a setRates that holds the zone, so a tariff never lands on an archived zone', function (): void {
    $zone = mtZone('ru')->id->value;
    $method = mtMethod('courier')->id->value;

    $outcomes = Concurrency::run(2, function (int $worker) use ($zone, $method): string {
        $zoneId = new ZoneId($zone);

        try {
            if ($worker === 0) {
                DB::listen(static function ($query): void {
                    if (str_contains($query->sql, 'cmz_shipping_zones') && str_contains($query->sql, 'for share')) {
                        usleep(700_000); // holds the zone while the other worker tries to archive it
                    }
                });

                mtMethods()->setRates(new SetMethodRates(new MethodId($method), [mtRate($zoneId)]));

                return 'rates set';
            }

            usleep(300_000);
            app(Zones::class)->archive(new ArchiveZone($zoneId));

            return 'zone archived';
        } catch (CommerzException $e) {
            return $e->code();
        }
    }, timeoutSeconds: 30);

    expect($outcomes)->toBe(['rates set', 'zone archived'])
        ->and(DB::table('cmz_shipping_zones')->value('status'))->toBe('archived')
        ->and(DB::table('cmz_shipping_rates')->count())->toBe(1);
})->group('concurrency');

it('refuses setRates on a zone that was archived first', function (): void {
    $zone = mtZone('ru')->id->value;
    $method = mtMethod('courier')->id->value;

    $outcomes = Concurrency::run(2, function (int $worker) use ($zone, $method): string {
        $zoneId = new ZoneId($zone);

        try {
            if ($worker === 1) {
                DB::listen(static function ($query): void {
                    if (str_contains($query->sql, 'cmz_shipping_zones') && str_contains($query->sql, 'for update')) {
                        usleep(700_000); // holds the zone while the other worker tries to price it
                    }
                });

                app(Zones::class)->archive(new ArchiveZone($zoneId));

                return 'zone archived';
            }

            usleep(300_000);
            mtMethods()->setRates(new SetMethodRates(new MethodId($method), [mtRate($zoneId)]));

            return 'rates set';
        } catch (CommerzException $e) {
            return $e->code();
        }
    }, timeoutSeconds: 30);

    expect($outcomes)->toBe(['shipping.zone_archived', 'zone archived'])
        ->and(DB::table('cmz_shipping_rates')->count())->toBe(0);
})->group('concurrency');
