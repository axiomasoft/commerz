<?php

declare(strict_types=1);

use Commerz\Money\Money;
use Commerz\Promotions\Definitions\Data\ArchivePromotion;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Commerz\Promotions\Facades\Promotions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Usage/Helpers.php';

uses(RefreshDatabase::class);

/** Promotions, a coupon and customers with consumed and restored orders, all counted by the port. */
function rcnUsage(): void
{
    $limited = cpnPromotion('limited', ['limits' => new UsageLimits(total: 10, perCustomer: 3), 'budget' => Money::of('1000.00', 'RUB')]);
    cpnCoupon($limited, 'CODE', 5);
    $free = pdfPromotion('free');

    usgConsume('order:1', [usgApplied($limited, '100.00', 'CODE'), usgApplied($free)], 'customer:a');
    usgConsume('order:2', [usgApplied($limited, '50.00', 'CODE')], 'customer:b');
    usgConsume('order:3', [usgApplied($limited, '25.00', 'CODE')], 'customer:a');
    usgRestore('order:3');
}

it('reports no discrepancy when the counters match the usage log', function (): void {
    rcnUsage();

    $this->pendingArtisan('commerz:promotions:reconcile')
        ->expectsOutputToContain('Promotions are consistent.')
        ->assertExitCode(0);
});

it('lists every counter that disagrees with the usage log', function (): void {
    rcnUsage();
    DB::table('cmz_promotions')->where('code', 'limited')->update(['used' => 5, 'spent_minor' => 1]);
    DB::table('cmz_promotions')->where('code', 'free')->update(['used' => 1]);
    DB::table('cmz_coupons')->where('code', 'CODE')->update(['used' => 0]);
    DB::table('cmz_promotion_customer_usages')->where('customer_ref', 'customer:b')->delete();
    DB::table('cmz_promotion_customer_usages')->where('customer_ref', 'customer:a')->update(['used' => 2]);

    $this->pendingArtisan('commerz:promotions:reconcile')
        ->expectsTable(['kind', 'ref', 'expected', 'actual'], [
            ['promotion_used', 'promotion:free', '0', '1'],
            ['promotion_used', 'promotion:limited', '2', '5'],
            ['promotion_spent', 'promotion:limited', '15000', '1'],
            ['coupon_used', 'coupon:CODE', '2', '0'],
            ['customer_used', 'promotion:limited/customer:a', '1', '2'],
            ['customer_used', 'promotion:limited/customer:b', '1', '0'],
        ])
        ->expectsOutputToContain('6 discrepancies.')
        ->assertExitCode(1);
});

it('lists the rules and activators no plugin can run', function (): void {
    rcnUsage();
    $limited = DB::table('cmz_promotions')->where('code', 'limited')->value('id');
    DB::table('cmz_promotion_rules')->where('promotion_id', $limited)->where('role', 'effect')->update(['rule_key' => 'gone.rule']);
    DB::table('cmz_promotions')->where('code', 'free')->update(['activator' => 'gone.activator']);
    $archived = pdfPromotion('archived');
    Promotions::promotions()->archive(new ArchivePromotion($archived->id));
    DB::table('cmz_promotion_rules')->where('promotion_id', $archived->id->value)->update(['rule_key' => 'gone.rule']);

    $this->pendingArtisan('commerz:promotions:reconcile')
        ->expectsTable(['kind', 'ref', 'expected', 'actual'], [
            ['unsupported_activator', 'promotion:free', 'supported', "no activator contributes 'gone.activator'"],
            ['unsupported_rule', 'promotion:limited/effect.0', 'supported', "no effect contributes 'gone.rule'"],
        ])
        ->assertExitCode(1);
});
