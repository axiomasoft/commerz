<?php

declare(strict_types=1);

use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\Taxes\Data\DefineTaxZone;
use Commerz\Pricing\Taxes\Data\SetTaxZoneMembers;
use Commerz\Pricing\Taxes\Data\TaxZoneMember;
use Commerz\Pricing\Taxes\Exceptions\TaxRateOverlap;
use Commerz\Pricing\Taxes\Exceptions\TaxZoneMemberTaken;
use Commerz\Testkit\Concurrency;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';

// Committed data across processes: no transactional isolation; the tables are emptied after each test. Lock timeouts
// stay at the default (3 s): a deadlock surfaces as SQLSTATE 40P01 and fails the worker.

beforeEach(function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required.');
    }

    $this->artisan('migrate')->assertSuccessful();
});

afterEach(function (): void {
    DB::statement('truncate cmz_tax_rates, cmz_tax_zone_members, cmz_tax_zones, cmz_tax_classes,'
        .' cmz_outbox_messages, cmz_operation_receipts cascade');
});

it('keeps one rate when processes set overlapping windows of one key (TAX-01)', function (): void {
    txClass();
    txZone();

    $outcomes = Concurrency::run(6, function (int $worker): string {
        // Every window covers 2026-06-01, so any two of them overlap.
        try {
            txRate(bp: 2000 + $worker, from: txAt('2026-0'.(1 + $worker).'-01'), until: txAt('2026-12-01'));

            return 'set';
        } catch (TaxRateOverlap) {
            return 'overlap';
        }
    });

    $counts = array_count_values($outcomes);
    ksort($counts);

    expect($counts)->toBe(['overlap' => 5, 'set' => 1])
        ->and(DB::table('cmz_tax_rates')->count())->toBe(1);
})->group('concurrency');

it('keeps one row and no error when processes set the identical rate (TAX-01)', function (): void {
    txClass();
    txZone();

    $rates = Concurrency::run(6, fn (int $worker): int => txRate(bp: 2000 + $worker, from: txAt('2026-01-01'), until: txAt('2026-02-01'))->rate->basisPoints);

    expect(DB::table('cmz_tax_rates')->count())->toBe(1)
        ->and(DB::table('cmz_tax_rates')->value('rate_bp'))->toBeIn($rates);
})->group('concurrency');

it('puts a country in one zone only when processes claim it for different zones (TAX-01)', function (): void {
    foreach (['z0', 'z1', 'z2', 'z3'] as $zone) {
        Pricing::taxes()->defineZone(new DefineTaxZone($zone, strtoupper($zone)));
    }

    $outcomes = Concurrency::run(4, function (int $worker): string {
        // The same two countries in every zone: whoever lost the first would have lost the second as well.
        try {
            Pricing::taxes()->setZoneMembers(new SetTaxZoneMembers("z{$worker}", $worker % 2 === 0
                ? [new TaxZoneMember('DE'), new TaxZoneMember('RU')]
                : [new TaxZoneMember('RU'), new TaxZoneMember('DE')]));

            return 'set';
        } catch (TaxZoneMemberTaken) {
            return 'taken';
        }
    });

    $counts = array_count_values($outcomes);
    ksort($counts);

    expect($counts)->toBe(['set' => 1, 'taken' => 3])
        ->and(DB::table('cmz_tax_zone_members')->count())->toBe(2)
        ->and(DB::table('cmz_tax_zone_members')->distinct()->count('tax_zone_id'))->toBe(1);
})->group('concurrency');

it('refuses reciprocal zone member claims without deadlocking or losing the original members', function (): void {
    txZone('left', [new TaxZoneMember('DE')]);
    txZone('right', [new TaxZoneMember('FR')]);

    $outcomes = Concurrency::run(2, function (int $worker): string {
        // Hold each deletion open long enough for the other writer to reach its own deletion. Sorted inserts alone
        // cannot stop their unique checks from waiting on one another's uncommitted deletions.
        DB::listen(static function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'delete from "cmz_tax_zone_members"')) {
                usleep(200_000);
            }
        });

        try {
            Pricing::taxes()->setZoneMembers(new SetTaxZoneMembers($worker === 0 ? 'left' : 'right', [
                new TaxZoneMember('DE'), new TaxZoneMember('FR'),
            ]));

            return 'set';
        } catch (TaxZoneMemberTaken) {
            return 'taken';
        }
    });

    expect($outcomes)->toBe(['taken', 'taken'])
        ->and(Pricing::taxes()->listZones()[0]->members)->toEqual([new TaxZoneMember('DE')])
        ->and(Pricing::taxes()->listZones()[1]->members)->toEqual([new TaxZoneMember('FR')]);
})->group('concurrency');
